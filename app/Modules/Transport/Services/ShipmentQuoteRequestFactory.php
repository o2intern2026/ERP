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

        // CHANGE_REQUESTS #118: before packing there is no fulfilment yet — quote from the warehouse holding the order's goods (its ASN), else the first active one.
        $warehouseId = ($shipment->fulfilment_id === null
            ? null
            : DB::table('fulfilments')->where('id', $shipment->fulfilment_id)->value('warehouse_id'))
            ?? $this->defaultWarehouseId((int) $shipment->order_id);
        $warehouse = $warehouseId === null
            ? null
            : DB::table('warehouses')->where('id', $warehouseId)->first();

        if ($warehouse === null) {
            return null;
        }

        return self::partyForWarehouse($warehouse);
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

        $declared = DB::table('declared_packages')
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
        if ($declared !== [] || ! Schema::hasTable('order_lines')) {
            return $declared;
        }

        // CHANGE_REQUESTS #118: a from_stock order declares its goods per line (cartons × weight / dims) — that is the preliminary parcel list.
        return self::itemsFromLines(DB::table('order_lines')->where('order_id', $shipment->order_id)->orderBy('id')->get());
    }

    /**
     * Parcels from goods lines (order lines or the portal form before the order exists): one item per line with its carton count as the
     * quantity; `actual_weight_kg` is the line total, so the per-carton weight is that divided by the cartons. Lines without a weight
     * and all three dimensions cannot be priced by a carrier and are left out.
     *
     * @param  iterable<object|array<string, mixed>>  $lines
     * @return list<array{description:string, qty:int, weight_kg:float, length_mm:int, width_mm:int, height_mm:int}>
     */
    public static function itemsFromLines(iterable $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            $line = (object) $line;
            $qty = max(0, (int) ($line->carton_qty ?? 0));
            $weight = (float) ($line->actual_weight_kg ?? 0);
            $dims = [(int) ($line->length_mm ?? 0), (int) ($line->width_mm ?? 0), (int) ($line->height_mm ?? 0)];
            if ($qty < 1 || $weight <= 0 || min($dims) <= 0) {
                continue;
            }
            $items[] = [
                'description' => (string) (($line->description_en ?? null) ?: (($line->description_cn ?? null) ?: ($line->package_type ?? 'carton'))),
                'qty' => $qty,
                'weight_kg' => round($weight / $qty, 3),
                'length_mm' => $dims[0],
                'width_mm' => $dims[1],
                'height_mm' => $dims[2],
            ];
        }

        return $items;
    }

    /** The carrier-request party for a warehouse row (name, address, suburb, state, postcode); null while the address is incomplete. */
    public static function partyForWarehouse(object $warehouse): ?array
    {
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

    /** The warehouse of the order's goods (through its ASN lines), else the first active warehouse. */
    private function defaultWarehouseId(int $orderId): ?int
    {
        if (Schema::hasTable('order_lines') && Schema::hasTable('asn_lines') && Schema::hasTable('asns')) {
            $fromAsn = DB::table('order_lines')
                ->join('asn_lines', 'asn_lines.id', '=', 'order_lines.asn_line_id')
                ->join('asns', 'asns.id', '=', 'asn_lines.asn_id')
                ->where('order_lines.order_id', $orderId)
                ->value('asns.warehouse_id');
            if ($fromAsn !== null) {
                return (int) $fromAsn;
            }
        }
        $first = DB::table('warehouses')->where('active', true)->orderBy('code')->value('id');

        return $first === null ? null : (int) $first;
    }

    private function declaredValueCents(int $orderId): int
    {
        if (! Schema::hasTable('order_lines')) {
            return 0;
        }

        return (int) DB::table('order_lines')->where('order_id', $orderId)->sum('total_price_cents');
    }

    public static function completeParty(array $party): bool
    {
        foreach (['address', 'suburb', 'state', 'postcode', 'type'] as $key) {
            if (trim((string) ($party[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }
}
