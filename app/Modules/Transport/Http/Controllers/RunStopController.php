<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Http\TransportValidation;
use App\Modules\Transport\Models\DeliveryRun;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\DeliveryRunService;
use App\Support\Auth\RequiredRoles;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class RunStopController extends Controller
{
    public function store(Request $request, DeliveryRun $deliveryRun, DeliveryRunService $service): RedirectResponse
    {
        $this->authorizeCoordinator();
        $validated = $request->validate([
            'shipment_id' => ['required', 'integer', 'exists:shipments,id'],
            'eta' => ['nullable', 'date'],
        ], TransportValidation::messages(), TransportValidation::attributes());

        try {
            $service->addShipment(
                $deliveryRun,
                Shipment::query()->findOrFail($validated['shipment_id']),
                $validated['eta'] ?? null,
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['shipment_id' => $exception->getMessage()]);
        }

        return back()->with('status', __('transport.runs.shipment_added'));
    }

    public function reorder(Request $request, DeliveryRun $deliveryRun, DeliveryRunService $service): RedirectResponse
    {
        $this->authorizeCoordinator();
        $validated = $request->validate([
            'positions' => ['required', 'array'],
            'positions.*' => ['required', 'integer', 'min:1'],
        ], TransportValidation::messages(), TransportValidation::attributes());

        $ordered = $this->orderedStopIds($deliveryRun, $validated['positions']);
        if ($ordered === null) {
            return back()->withInput()->withErrors(['positions' => __('transport.runs.duplicate_positions')]);
        }

        try {
            $service->reorder($deliveryRun, $ordered);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['positions' => $exception->getMessage()]);
        }

        return back()->with('status', __('transport.runs.order_saved'));
    }

    /**
     * 2026-09-10 audit: the 顺序 grid used to demand distinct numbers (`distinct`), so the natural gesture — change one
     * box — was refused with an English "duplicate value" naming a stop id. Distinct numbers are taken as given (sorted);
     * when exactly one stop's number changed it is moved to that position and the others keep their relative order;
     * several changed stops sharing a number is the one case still refused (in Chinese, input kept).
     *
     * @param  array<int|string, int|string>  $positions  stop id → requested position
     * @return list<int>|null
     */
    private function orderedStopIds(DeliveryRun $run, array $positions): ?array
    {
        $requested = collect($positions)->mapWithKeys(fn ($position, $id): array => [(int) $id => (int) $position]);
        if ($requested->count() === $requested->unique()->count()) {
            return $requested->sort()->keys()->values()->all();
        }

        /** @var Collection<int, int> $current stop id → stored seq */
        $current = $run->stops()->orderBy('seq')->pluck('seq', 'id')->map(fn ($seq): int => (int) $seq);
        $moved = $requested->filter(fn (int $position, int $id): bool => $position !== ($current[$id] ?? null));
        if ($moved->count() !== 1) {
            return null;
        }

        $movedId = (int) $moved->keys()->first();
        $others = $requested->keys()
            ->reject(fn (int $id): bool => $id === $movedId)
            ->sortBy(fn (int $id): int => $current[$id] ?? PHP_INT_MAX)
            ->values()
            ->all();
        $index = min(max((int) $moved->first() - 1, 0), count($others));
        array_splice($others, $index, 0, [$movedId]);

        return array_values($others);
    }

    private function authorizeCoordinator(): void
    {
        RequiredRoles::requireAny(['admin', 'customer_service', 'dispatcher', 'transport_operator']);
    }
}
