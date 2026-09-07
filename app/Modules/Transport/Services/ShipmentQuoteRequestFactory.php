<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Builds the CarrierAdapter request from read-only Orders and Warehouse contract projections. */
class ShipmentQuoteRequestFactory
{
    public function __construct(private readonly PackageManifest $packages) {}

    /** @return array<string, mixed>|null */
    public function build(Shipment $shipment, string $stage): ?array
    {
        if (! Schema::hasTable('orders')) {
            return null;
        }

        $order = DB::table('orders')->where('id', $shipment->order_id)->first();
        if ($order === null) {
            return null;
        }

        $receiver = [
            'name' => $order->deliver_to_name,
            'company_name' => $order->deliver_to_name,
            'phone' => $order->deliver_to_phone,
            'email' => null,
            'address' => $order->deliver_to_address,
            'suburb' => $order->deliver_to_suburb,
            'state' => $order->deliver_to_state,
            'postcode' => $order->deliver_to_postcode,
            'type' => $order->deliver_to_address_type ?? 'business',
        ];

        $sender = $this->sender($order, $shipment);
        $items = $this->items($shipment, $stage);
        if ($sender === null || ! $this->completeParty($receiver) || $items === []) {
            return null;
        }

        return [
            'client_id' => $shipment->client_id,
            'sender' => $sender,
            'receiver' => $receiver,
            'items' => $items,
            'declared_value_cents' => $this->declaredValueCents($shipment->order_id),
            'description' => $shipment->shipment_no,
            'tailgate_pickup' => false,
            'tailgate_delivery' => $shipment->tailgate_required,
            'requested_date' => isset($order->requested_date) ? (string) $order->requested_date : null,
            'zone' => (string) $order->deliver_to_postcode,
        ];
    }

    /** @return array<string, mixed>|null */
    private function sender(object $order, Shipment $shipment): ?array
    {
        if (($order->order_type ?? null) === 'pickup_deliver') {
            $pickup = is_string($order->pickup_address ?? null)
                ? json_decode($order->pickup_address, true)
                : (array) ($order->pickup_address ?? []);

            $pickup['type'] ??= 'business';

            return $this->completeParty($pickup) ? $pickup : null;
        }

        if (! Schema::hasTable('fulfilments') || ! Schema::hasTable('warehouses')) {
            return null;
        }

        $warehouseId = $shipment->fulfilment_id === null
            ? null
            : DB::table('fulfilments')->where('id', $shipment->fulfilment_id)->value('warehouse_id');
        $warehouse = $warehouseId === null
            ? null
            : DB::table('warehouses')->where('id', $warehouseId)->first();

        if ($warehouse === null) {
            return null;
        }

        $sender = [
            'name' => $warehouse->name,
            'company_name' => $warehouse->name,
            'phone' => null,
            'email' => null,
            'address' => $warehouse->address,
            'suburb' => $warehouse->suburb ?? '',
            'state' => $warehouse->state,
            'postcode' => $warehouse->postcode ?? '',
            'type' => 'business',
        ];

        return trim((string) $sender['address']) !== '' && trim((string) $sender['state']) !== '' ? $sender : null;
    }

    /** @return list<array{description:string, qty:int, weight_kg:float, length_mm:int, width_mm:int, height_mm:int}> */
    private function items(Shipment $shipment, string $stage): array
    {
        if ($stage === 'final') {
            $packages = $this->packages->forShipment($shipment);
            if ($packages !== []) {
                return array_map(fn (array $package): array => [
                    'description' => (string) ($package['carton_label'] ?: $package['package_type']),
                    'qty' => 1,
                    'weight_kg' => (float) $package['weight_kg'],
                    'length_mm' => (int) $package['length_mm'],
                    'width_mm' => (int) $package['width_mm'],
                    'height_mm' => (int) $package['height_mm'],
                ], $packages);
            }
        }

        if (! Schema::hasTable('declared_packages')) {
            return [];
        }

        return DB::table('declared_packages')
            ->where('order_id', $shipment->order_id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $package): array => [
                'description' => (string) $package->package_type,
                'qty' => (int) $package->qty,
                'weight_kg' => (float) $package->weight_kg,
                'length_mm' => (int) $package->length_mm,
                'width_mm' => (int) $package->width_mm,
                'height_mm' => (int) $package->height_mm,
            ])
            ->all();
    }

    private function declaredValueCents(int $orderId): int
    {
        if (! Schema::hasTable('order_lines')) {
            return 0;
        }

        return (int) DB::table('order_lines')->where('order_id', $orderId)->sum('total_price_cents');
    }

    private function completeParty(array $party): bool
    {
        foreach (['address', 'suburb', 'state', 'postcode', 'type'] as $key) {
            if (trim((string) ($party[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }
}
