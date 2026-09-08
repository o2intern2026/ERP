<?php

namespace App\Support\Contracts;

/**
 * One implementation per carrier_services.source (contracts/enums.md): manual, own_fleet, transdirect, karrio (eiz is out
 * of phase 1 — contracts/carriers.md). Implemented by Transport (seat X2, B5c); TransportOptionService aggregates them.
 * Money is integer cents, dimensions mm, weights kg. Never throw for a "no quote" outcome — return an empty list.
 */
interface CarrierAdapter
{
    /** contracts/enums.md carrier_services.source */
    public function source(): string;

    /**
     * @return array{quote:bool, book:bool, cancel:bool, label:bool, tracking:'poll'|'webhook'|'none', pod:'api'|'manual'}
     */
    public function capabilities(): array;

    /**
     * @param  array{sender:array{name?:string, company_name?:string, phone?:string, email?:string, address:string, suburb:string, state:string, postcode:string, type:string}, receiver:array{name?:string, company_name?:string, phone?:string, email?:string, address:string, suburb:string, state:string, postcode:string, type:string}, items:list<array{description:string, qty:int, weight_kg:float, length_mm:int, width_mm:int, height_mm:int}>, declared_value_cents:int, tailgate_pickup:bool, tailgate_delivery:bool, requested_date?:string}  $request
     * @return list<array{service_code:string, service_name:string, service_level:string, cost_cents:int, eta_days:?int, pickup_dates:list<string>, raw:array<string, mixed>}>
     */
    public function quote(array $request): array;

    /**
     * Book the chosen service for an already quoted request.
     *
     * @param  array<string, mixed>  $request  same shape as quote()
     * @param  array{pickup_date?:string, quote_ref?:string}  $options
     * @return array{booking_ref:string, tracking_number:?string, label_path:?string, status:string, raw:array<string, mixed>}
     */
    public function book(array $request, string $serviceCode, array $options = []): array;

    /** True when the carrier accepted the cancellation. */
    public function cancel(string $bookingRef): bool;

    /** Label / waybill PDF bytes, or null when the source has none (manual, own_fleet uses its own label). */
    public function label(string $bookingRef): ?string;

    /**
     * Latest tracking events (polling sources) — empty list for sources with tracking 'none'.
     *
     * @return list<array{status:string, description:?string, location:?string, occurred_at:?string, raw:array<string, mixed>}>
     */
    public function tracking(string $bookingRef): array;
}
