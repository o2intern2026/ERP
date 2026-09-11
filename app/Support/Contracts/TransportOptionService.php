<?php

namespace App\Support\Contracts;

/**
 * Provided by Transport (seat X2, M5). contracts/services.md §3.
 * Aggregates own_fleet / transdirect / eiz / manual CarrierAdapter quotes into transport_quotes rows.
 */
interface TransportOptionService
{
    /**
     * @param  'preliminary'|'final'  $stage  preliminary = declared packages at order confirmation; final = measured packages after outbound.packed
     * @return list<array{transport_quote_id:int, carrier_id:int, source:string, service_level:string, cost_cents:int, customer_price_cents:int, eta_days:int, is_recommended:bool, is_cheapest:bool, is_fastest:bool, quoted_at:string, expires_at:string}>
     */
    public function quote(int $shipmentId, string $stage): array;

    /**
     * CHANGE_REQUESTS #118: price a quote request with no shipment behind it (the portal's 获取估价 before the order exists) — the same
     * adapters, customer pricing and flags as quote(), nothing written, no exception raised (an empty list = no automatic option).
     *
     * @param  array<string, mixed>  $request  the CarrierAdapter request shape (sender, receiver, items, requested_date, tailgate_delivery, zone, client_id)
     * @return list<array{carrier_id:int, carrier_name:?string, source:string, service_level:string, customer_price_cents:int, eta_days:?int, is_recommended:bool, is_cheapest:bool, is_fastest:bool}>
     */
    public function estimate(int $clientId, array $request): array;
}
