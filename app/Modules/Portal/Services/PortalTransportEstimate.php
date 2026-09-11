<?php

namespace App\Modules\Portal\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Transport\Services\ShipmentQuoteRequestFactory;
use App\Support\Contracts\TransportOptionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * CHANGE_REQUESTS #118: the transport options shown with the 估价 on the portal order form, before the order exists. Builds the same
 * carrier request Transport would build for the order (warehouse → consignee, parcels from the declared cartons) and asks
 * TransportOptionService::estimate() — customer prices and flags only, nothing written. The option the client ticks is stored on
 * the order as `transport_preference`; Transport selects / confirms that option later on its own.
 */
final class PortalTransportEstimate
{
    /** Snapshot fields kept on the order — customer-facing only, never cost or markup. */
    private const SNAPSHOT = ['carrier_id', 'carrier_name', 'source', 'service_level', 'customer_price_cents', 'eta_days', 'is_recommended', 'is_cheapest', 'is_fastest'];

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
                return Arr::only($option, self::SNAPSHOT) + ['chosen_at' => now()->toIso8601String(), 'chosen_by' => $userId];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $option */
    public static function key(array $option): string
    {
        return $option['source'].'|'.$option['service_level'].'|'.($option['carrier_id'] ?? '');
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
            $warehouse = $this->defaultWarehouse($clientId);
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

    /** The warehouse holding the client's goods (its latest ASN), else the first active warehouse. */
    private function defaultWarehouse(int $clientId): ?object
    {
        $warehouseId = DB::table('asns')->where('client_id', $clientId)->orderByDesc('id')->value('warehouse_id');
        $warehouse = $warehouseId === null ? null : DB::table('warehouses')->where('id', $warehouseId)->first();

        return $warehouse ?? DB::table('warehouses')->where('active', true)->orderBy('code')->first();
    }
}
