<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Support\Contracts\ExceptionService;
use App\Support\Enums;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Holds are Platform exceptions with type = hold (ERP_PLAN §3.3). Writes go through ExceptionService; this class only
 * reads the shared table for list views and writes the order timeline. Only an active financial hold blocks booking /
 * dispatch (OMS-11 / A13): Finance or admin place it, Finance / admin / the dispatcher (Coordinator) release it, both
 * with a reason that lands in the timeline together with the actor. The system never places one automatically.
 */
final class OrderHoldService
{
    /** May place a financial hold. */
    public const FINANCE_ROLES = ['admin', 'finance'];

    /** May release a financial hold (ERP_PLAN §3.4: Finance / Coordinator). */
    public const FINANCIAL_RELEASE_ROLES = ['admin', 'finance', 'dispatcher'];

    /** May place / release every other hold type. */
    public const HOLD_ROLES = ['admin', 'customer_service', 'dispatcher', 'finance'];

    public function __construct(private readonly ExceptionService $exceptions) {}

    public function place(Order $order, string $holdType, string $reason, ?int $actorId): int
    {
        if (! in_array($holdType, Enums::HOLD_TYPES, true)) {
            throw new InvalidArgumentException("Unknown hold_type: {$holdType}");
        }

        return DB::transaction(function () use ($order, $holdType, $reason, $actorId): int {
            $id = $this->exceptions->raise('hold', 'orders', [
                'client_id' => $order->client_id, 'job_id' => $order->job_id, 'order_id' => $order->id,
                'source_type' => 'order', 'source_id' => $order->id, 'hold_type' => $holdType, 'message' => $reason, 'created_by' => $actorId,
            ]);
            $this->timeline($order, $actorId, __('orders.holds.timeline.placed', ['type' => __('orders.holds.types.'.$holdType), 'reason' => $reason]));

            return $id;
        });
    }

    public function release(Order $order, int $exceptionId, string $note, int $actorId): void
    {
        $hold = $this->activeFor($order)->firstWhere('id', $exceptionId);
        if ($hold === null) {
            throw new InvalidArgumentException('No active hold with that id on this order.');
        }

        DB::transaction(function () use ($order, $hold, $note, $actorId): void {
            $this->exceptions->resolve((int) $hold->id, $actorId, $note);
            $this->timeline($order, $actorId, __('orders.holds.timeline.released', ['type' => __('orders.holds.types.'.$hold->hold_type), 'note' => $note]));

            // A13: a dispatch the warehouse already reported while the lock was active now goes through.
            if ($hold->hold_type === 'financial' && ! $this->hasActiveFinancialHold($order)) {
                app(FulfilmentService::class)->resyncOperationalStatus($order);
            }
        });
    }

    /** Which roles may place or release a hold of this type. */
    public static function rolesFor(string $holdType, bool $release = false): array
    {
        if ($holdType === 'financial') {
            return $release ? self::FINANCIAL_RELEASE_ROLES : self::FINANCE_ROLES;
        }

        return self::HOLD_ROLES;
    }

    /** Active holds on the order itself or client-wide (order_id null) — read-only view of the Platform table. */
    public function activeFor(Order $order): Collection
    {
        return DB::table('exceptions')
            ->where('type', 'hold')->where('status', '!=', 'resolved')
            ->where('client_id', $order->client_id)
            ->where(fn ($q) => $q->where('order_id', $order->id)->orWhereNull('order_id'))
            ->orderBy('id')
            ->get(['id', 'hold_type', 'message', 'owner_id', 'order_id', 'created_by', 'created_at']);
    }

    /** @return array<int, list<string>> order_id → active hold types (client-wide holds apply to every order of the client) */
    public function activeTypesFor(Collection $orders): array
    {
        if ($orders->isEmpty()) {
            return [];
        }
        $rows = DB::table('exceptions')->where('type', 'hold')->where('status', '!=', 'resolved')
            ->whereIn('client_id', $orders->pluck('client_id')->unique())
            ->get(['client_id', 'order_id', 'hold_type']);

        $map = [];
        foreach ($orders as $order) {
            $types = $rows->filter(fn ($r) => (int) $r->client_id === (int) $order->client_id && ($r->order_id === null || (int) $r->order_id === (int) $order->id))->pluck('hold_type')->unique()->values()->all();
            if ($types !== []) {
                $map[$order->id] = $types;
            }
        }

        return $map;
    }

    public function hasActiveFinancialHold(Order $order): bool
    {
        return $this->exceptions->hasActiveHold('financial', $order->client_id, $order->id);
    }

    private function timeline(Order $order, ?int $actorId, string $note): void
    {
        OrderEvent::query()->create([
            'order_id' => $order->id, 'dimension' => 'operational', 'from_status' => $order->operational_status, 'to_status' => $order->operational_status,
            'actor_type' => $actorId === null ? 'system' : 'user', 'actor_id' => $actorId, 'note' => $note, 'created_at' => now(),
        ]);
    }
}
