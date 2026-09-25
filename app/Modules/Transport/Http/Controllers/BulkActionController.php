<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Http\TransportValidation;
use App\Modules\Transport\Services\ShipmentBulkActionService;
use App\Support\Auth\RequiredRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** CHANGE_REQUESTS #160 / #161: 批量确认最终方案 and 批量确认预订 from the transport board — planners only (#130). */
class BulkActionController extends Controller
{
    public const ROLES = ['admin', 'customer_service', 'dispatcher'];

    public function confirmQuotes(Request $request, ShipmentBulkActionService $bulk): RedirectResponse
    {
        RequiredRoles::requireAny(self::ROLES);

        return $this->respond($bulk->confirmQuotes($this->ids($request), (int) $request->user()->id), 'transport.bulk_confirm');
    }

    public function book(Request $request, ShipmentBulkActionService $bulk): RedirectResponse
    {
        RequiredRoles::requireAny(self::ROLES);

        return $this->respond($bulk->book($this->ids($request)), 'transport.bulk_book');
    }

    /** @return list<int> */
    private function ids(Request $request): array
    {
        $data = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['integer', 'exists:shipments,id'],
        ], TransportValidation::messages(), TransportValidation::attributes() + ['shipment_ids' => __('transport.shipments.number')]);

        return array_values(array_unique(array_map('intval', $data['shipment_ids'])));
    }

    /** @param  array{done: list<string>, refused: array<string, string>}  $result */
    private function respond(array $result, string $key): RedirectResponse
    {
        $response = redirect()->to(url()->previous() ?: route('transport.index'));
        if ($result['done'] !== []) {
            $response->with('status', __($key.'.done', ['count' => count($result['done'])]));
        }
        if ($result['refused'] !== []) {
            $lines = collect($result['refused'])
                ->map(fn (string $reason, string $shipmentNo): string => __($key.'.refused_line', ['shipment' => $shipmentNo, 'reason' => $reason]))
                ->implode('；');
            $response->withErrors(['shipment_ids' => __($key.'.refused', ['count' => count($result['refused']), 'lines' => $lines])]);
        }

        return $response;
    }
}
