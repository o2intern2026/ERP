<?php

namespace App\Support\Contracts;

use DateTimeInterface;

/**
 * Provided by Billing (seat C, M6). contracts/services.md §4.
 * Rate lookup order: client rate card → the client's bound standard card → Missing Rate (never $0).
 */
interface RateService
{
    /**
     * Price one charge code for a client at a point in time.
     *
     * @param  array{pallet_class?:string, weight_kg?:float, zone?:string, carrier_id?:int, service_level?:string, cost_cents?:int, container_size?:string, unpack_mode?:string, line_count?:int, gross_weight_kg?:float, pallet_source?:string, is_urgent?:bool}  $context
     * @return array{charge_code:string, rate_item_id:?int, rate_card_id:?int, rate_card_version:?int, uom:?string, qty:float, rate_cents:?int, amount_cents:?int, min_charge_applied:bool, is_poa:bool, missing_rate:bool, calculation_snapshot:array<string, mixed>}
     */
    public function price(int $clientId, string $chargeCode, float $qty, array $context = [], ?DateTimeInterface $at = null): array;

    /**
     * threshold_json of the client's effective rate item for a code (client card → standard card), or null when neither has it.
     *
     * @return array<string, mixed>|null
     */
    public function thresholds(int $clientId, string $chargeCode): ?array;

    /**
     * Suggest a pallet_class from the client's storage thresholds (ERP_PLAN §4.8):
     * standard → oversize_high → oversize_wide; weight ≥ min_weight_kg → overweight; null = beyond every band (POA).
     */
    public function suggestPalletClass(int $clientId, int $lengthMm, int $widthMm, int $heightMm, float $weightKg): ?string;
}
