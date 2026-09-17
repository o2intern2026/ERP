<?php

namespace App\Modules\Portal\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderInboundService;
use App\Modules\Transport\Services\ShipmentQuoteRequestFactory;
use App\Modules\Transport\Support\CollectionTailgate;
use App\Support\Contracts\TransportOptionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * CHANGE_REQUESTS #118: the transport options shown with the 估价 on the portal order form, before the order exists. Builds the same
 * carrier request Transport would build for the order (warehouse → consignee, parcels from the declared cartons) and asks
 * TransportOptionService::estimate() — customer prices and flags only, nothing written. The option the client ticks is stored on
 * the order as `transport_preference`; Transport selects / confirms that option later on its own.
 *
 * CHANGE_REQUESTS #125: collectionOptions() does the same for a portal 入库清单 with 需要我们上门提货 — the plans for collecting the
 * goods at the client's pickup address and bringing them to our warehouse, before any ASN exists.
 */
final class PortalTransportEstimate
{
    /** Snapshot fields kept on the order / the collection request — customer-facing only, never cost or markup. */
    public const SNAPSHOT = ['carrier_id', 'carrier_name', 'source', 'service_level', 'customer_price_cents', 'eta_days', 'is_recommended', 'is_cheapest', 'is_fastest'];

    public function __construct(private readonly TransportOptionService $transport) {}

    /**
     * @return array{options: list<array<string, mixed>>, reason: ?string} reason: no_items (weights / dimensions missing) | no_address | none (no automatic option) | null
     */
    public function options(int $clientId, Order $order): array
    {
        $request = $this->request($clientId, $order);
        if (is_string($request)) {
            return ['options' => [], 'reason' => $request];
        }

        $options = array_map(fn (array $o): array => $o + ['key' => self::key($o)], $this->transport->estimate($clientId, $request));

        return ['options' => $options, 'reason' => $options === [] ? 'none' : null];
    }

    /** The option the client ticked, as the snapshot stored on the order (null when it is no longer offered). */
    public function choose(int $clientId, Order $order, string $key, ?int $userId): ?array
    {
        foreach ($this->options($clientId, $order)['options'] as $option) {
            if ($option['key'] === $key) {
                return self::snapshot($option, $userId);
            }
        }

        return null;
    }

    /**
     * CHANGE_REQUESTS #125: the collection plans for a portal 入库清单 whose client asked us to collect. The carrier request is built by
     * the SAME code Transport's ShipmentQuoteRequestFactory::buildCollection uses for the ASN customer service will generate from these
     * rows (collectionSender / collectionRequest): sender = the pickup address, receiver = the chosen warehouse, parcels = the rows as the
     * ASN goods lines they become (cartons, line weight, dims, description), zone = the pickup postcode, tailgate at pickup by
     * CollectionTailgate — only `description` differs ('estimate' instead of the shipment number). Customer prices and flags only,
     * nothing written.
     *
     * @param  array<string, mixed>  $collection  errors.context.inbound.collection {warehouse_id, address, ready_date, notes}
     * @param  list<array<string, mixed>>  $rows  the parser rows of the ready groups (carton_qty, actual_weight_kg = line total, dims, row) and, for a manual list (CHANGE_REQUESTS #128), the attached orders' lines in the same shape with `label` = the order no
     * @return array{options: list<array<string, mixed>>, reason: ?string, packages: list<array<string, mixed>>, unpriced_rows: list<int|string>} reason: no_address | no_items | none | null; a package / unpriced entry of an attached order carries its `label` (order no) instead of a sheet row number
     */
    public function collectionOptions(int $clientId, array $collection, array $rows): array
    {
        $packages = [];
        $unpriced = [];
        $lines = [];
        foreach ($rows as $row) {
            $qty = max(0, (int) ($row['carton_qty'] ?? 0));
            $total = is_numeric($row['actual_weight_kg'] ?? null) ? (float) $row['actual_weight_kg'] : null;
            $dims = ['length_mm' => $this->mm($row['length_mm'] ?? null), 'width_mm' => $this->mm($row['width_mm'] ?? null), 'height_mm' => $this->mm($row['height_mm'] ?? null)];
            $package = ['row' => (int) ($row['row'] ?? 0), 'package_type' => (string) (($row['package_type'] ?? null) ?: 'carton'), 'qty' => $qty,
                'weight_kg' => $total === null || $qty < 1 ? null : round($total / $qty, 3)] + $dims;
            if (filled($row['label'] ?? null)) {
                $package['label'] = (string) $row['label']; // CHANGE_REQUESTS #128: a line of an attached ORDER (its order no), not a typed / sheet row
            }
            $packages[] = $package;
            if ($total === null || $total <= 0 || in_array(null, $dims, true) || min($dims) <= 0) {
                $unpriced[] = $package['label'] ?? (int) ($row['row'] ?? 0);
            }
            // The ASN goods line this row becomes (order line → OrderInboundService::linePayload → asn_lines) in the shape collectionItems() reads.
            $lines[] = ['carton_qty' => $qty, 'actual_weight_kg' => $total] + $dims + [
                'description_en' => OrderInboundService::asnLineDescription($row['description_cn'] ?? null, $row['description_en'] ?? null),
                'package_type' => $row['package_type'] ?? null,
            ];
        }
        $result = ['options' => [], 'reason' => null, 'packages' => $packages, 'unpriced_rows' => array_values(array_unique($unpriced))];

        $address = (array) ($collection['address'] ?? []);
        $sender = ShipmentQuoteRequestFactory::collectionSender($address);
        $warehouse = filled($collection['warehouse_id'] ?? null) ? DB::table('warehouses')->where('id', (int) $collection['warehouse_id'])->first() : null;
        $receiver = $warehouse === null ? null : ShipmentQuoteRequestFactory::partyForWarehouse($warehouse);
        if ($receiver === null || ! ShipmentQuoteRequestFactory::completeParty($sender)) {
            return ['reason' => 'no_address'] + $result;
        }

        $items = ShipmentQuoteRequestFactory::itemsFromLines($lines);
        if ($items === []) {
            return ['reason' => 'no_items'] + $result;
        }
        // Same pieces Warehouse hands Transport when no packages are declared (AsnService::collectionLines: cartons + weight + all dims).
        $priceable = array_values(array_filter($lines, fn (array $l): bool => $l['carton_qty'] > 0 && (float) $l['actual_weight_kg'] > 0
            && (int) $l['length_mm'] > 0 && (int) $l['width_mm'] > 0 && (int) $l['height_mm'] > 0));
        $tailgate = CollectionTailgate::required($clientId, [], array_map(fn (array $l): array => ['weight_kg' => (float) $l['actual_weight_kg'], 'expected_cartons' => $l['carton_qty']], $priceable));

        $readyDate = filled($collection['ready_date'] ?? null) ? (string) $collection['ready_date'] : null;
        $request = ShipmentQuoteRequestFactory::collectionRequest($clientId, $sender, $receiver, $items, $tailgate, $readyDate, 'estimate');
        $options = $request === null ? [] : array_map(fn (array $o): array => $o + ['key' => self::key($o)], $this->transport->estimate($clientId, $request));

        return ['options' => $options, 'reason' => $options === [] ? 'none' : null] + $result;
    }

    /**
     * The snapshot kept of an option the client ticked: SNAPSHOT keys only (never cost / markup), who and when.
     *
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>
     */
    public static function snapshot(array $option, ?int $userId): array
    {
        return Arr::only($option, self::SNAPSHOT) + ['chosen_at' => now()->toIso8601String(), 'chosen_by' => $userId];
    }

    /** @param array<string, mixed> $option */
    public static function key(array $option): string
    {
        return $option['source'].'|'.$option['service_level'].'|'.($option['carrier_id'] ?? '');
    }

    /** The warehouse holding the client's goods (its latest ASN), else the first active warehouse. */
    public static function defaultWarehouse(int $clientId): ?object
    {
        $warehouseId = DB::table('asns')->where('client_id', $clientId)->orderByDesc('id')->value('warehouse_id');
        $warehouse = $warehouseId === null ? null : DB::table('warehouses')->where('id', $warehouseId)->first();

        return $warehouse ?? DB::table('warehouses')->where('active', true)->orderBy('code')->first();
    }

    /** @return array<string, mixed>|string the carrier request, or the reason none can be built */
    private function request(int $clientId, Order $order): array|string
    {
        $receiver = [
            'name' => $order->deliver_to_name, 'company_name' => $order->deliver_to_name, 'phone' => $order->deliver_to_phone, 'email' => null,
            'address' => $order->deliver_to_address, 'suburb' => $order->deliver_to_suburb, 'state' => $order->deliver_to_state, 'postcode' => $order->deliver_to_postcode,
            'type' => $order->deliver_to_address_type ?? 'business',
        ];
        if (! ShipmentQuoteRequestFactory::completeParty($receiver)) {
            return 'no_address';
        }

        if ($order->order_type === 'pickup_deliver') {
            $sender = (array) ($order->pickup_address ?? []) + ['type' => 'business'];
            $items = collect($order->declaredPackages ?? [])->map(fn ($p): array => [
                'description' => (string) $p->package_type, 'qty' => (int) $p->qty, 'weight_kg' => (float) $p->weight_kg,
                'length_mm' => (int) $p->length_mm, 'width_mm' => (int) $p->width_mm, 'height_mm' => (int) $p->height_mm,
            ])->filter(fn (array $i): bool => $i['qty'] > 0 && $i['weight_kg'] > 0 && min($i['length_mm'], $i['width_mm'], $i['height_mm']) > 0)->values()->all();
            if (! ShipmentQuoteRequestFactory::completeParty($sender)) {
                return 'no_address';
            }
        } else {
            $warehouse = self::defaultWarehouse($clientId);
            $sender = $warehouse === null ? null : ShipmentQuoteRequestFactory::partyForWarehouse($warehouse);
            if ($sender === null) {
                return 'no_address';
            }
            $items = ShipmentQuoteRequestFactory::itemsFromLines($order->lines ?? []);
        }
        if ($items === []) {
            return 'no_items';
        }

        $requested = $order->requested_date;

        return [
            'client_id' => $clientId,
            'sender' => $sender,
            'receiver' => $receiver,
            'items' => $items,
            'declared_value_cents' => (int) collect($order->lines ?? [])->sum('total_price_cents'),
            'description' => 'estimate',
            'tailgate_pickup' => false,
            'tailgate_delivery' => (bool) $order->tailgate_required,
            'requested_date' => $requested === null ? null : (is_string($requested) ? $requested : $requested->toDateString()),
            'zone' => (string) $order->deliver_to_postcode,
        ];
    }

    private function mm(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
