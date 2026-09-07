<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Models\CarrierCost;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Support\Tenancy\ClientScope;

final class ShipmentMarginService
{
    /** @return array<string, mixed> */
    public function shipment(Shipment $shipment): array
    {
        $quote = $shipment->selected_quote_id === null
            ? null
            : TransportQuote::query()
                ->select(['id', 'shipment_id', 'customer_price_cents'])
                ->find($shipment->selected_quote_id);
        $public = [
            'shipment_id' => $shipment->id,
            'shipment_no' => $shipment->shipment_no,
            'order_id' => $shipment->order_id,
            'revenue_cents' => $quote?->customer_price_cents ?? 0,
        ];

        if (ClientScope::isClientRequest()) {
            return $public;
        }

        $cost = CarrierCost::query()->where('shipment_id', $shipment->id)->first();
        $payable = $cost?->actual_cost_cents ?? $cost?->expected_cost_cents;

        return $public + [
            'expected_cost_cents' => $cost?->expected_cost_cents,
            'actual_cost_cents' => $cost?->actual_cost_cents,
            'payable_cost_cents' => $payable,
            'margin_cents' => $payable === null ? null : $public['revenue_cents'] - $payable,
            'margin_is_estimate' => $cost === null || $cost->actual_cost_cents === null,
            'cost_status' => $cost === null ? 'missing' : ($cost->actual_cost_cents === null ? 'estimated' : 'confirmed'),
        ];
    }

    /** @return array<string, mixed> */
    public function order(int $orderId): array
    {
        $shipments = Shipment::query()
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->get(['id', 'shipment_no', 'order_id', 'selected_quote_id']);
        $rows = $shipments->map(fn (Shipment $shipment): array => $this->shipment($shipment))->all();
        $public = [
            'order_id' => $orderId,
            'revenue_cents' => array_sum(array_column($rows, 'revenue_cents')),
            'shipments' => $rows,
        ];

        if (ClientScope::isClientRequest()) {
            return $public;
        }

        $complete = collect($rows)->every(fn (array $row): bool => $row['payable_cost_cents'] !== null);
        $payable = array_sum(array_column($rows, 'payable_cost_cents'));

        return $public + [
            'expected_cost_cents' => array_sum(array_column($rows, 'expected_cost_cents')),
            'actual_cost_cents' => array_sum(array_column($rows, 'actual_cost_cents')),
            'payable_cost_cents' => $payable,
            'margin_cents' => $complete ? $public['revenue_cents'] - $payable : null,
            'margin_is_estimate' => ! $complete || collect($rows)->contains('margin_is_estimate', true),
            'cost_status' => ! $complete
                ? 'missing'
                : (collect($rows)->contains('margin_is_estimate', true) ? 'estimated' : 'confirmed'),
        ];
    }
}
