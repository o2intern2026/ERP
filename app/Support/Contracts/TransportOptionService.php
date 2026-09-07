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
}
