<?php

namespace App\Modules\Billing\Seeders;

use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\ChargeRule;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\MasterData\Models\Client;
use Illuminate\Database\Seeder;

/**
 * contracts/charge-codes.md as data: the 34 Edward rows (+ the codes the plan needs without an Edward rate), their
 * trigger rules, and the standard rate card "Edward 2026-02-27 v1". Rates are seed data, not contract. Idempotent.
 */
class BillingSeeder extends Seeder
{
    /** code => [category, uom, customer description, internal note] */
    public const CODES = [
        'TR-CARTAGE-20' => ['transport', 'container_20', 'Container delivery – 20ft sideloader', 'Below 22.5 t all in; above → POA'],
        'TR-CARTAGE-40' => ['transport', 'container_40', 'Container delivery – 40ft sideloader', 'Below 22.5 t all in; above → POA'],
        'WH-DEVAN-20-PLT' => ['warehouse', 'container_20', 'Container unpack 20ft – pallet', null],
        'WH-DEVAN-20-LOOSE' => ['warehouse', 'container_20', 'Container unpack 20ft – loose', 'Within 20 SKU lines; above → POA'],
        'WH-DEVAN-20-MIXED' => ['warehouse', 'container_20', 'Container unpack 20ft – mixed', 'POA ($300 subject to container)'],
        'WH-DEVAN-40-PLT' => ['warehouse', 'container_40', 'Container unpack 40ft – pallet', null],
        'WH-DEVAN-40-LOOSE' => ['warehouse', 'container_40', 'Container unpack 40ft – loose', 'Within 20 SKU lines; above → POA'],
        'WH-DEVAN-40-MIXED' => ['warehouse', 'container_40', 'Container unpack 40ft – mixed', 'POA ($450 subject to container)'],
        'WH-UNLOAD-PLT' => ['warehouse', 'pallet', 'Truck unload (LCL) – pallet', null],
        'WH-PUTAWAY-PLT' => ['warehouse', 'pallet', 'Putaway – pallet', null],
        'WH-WRAP-IN-PLT' => ['vas', 'pallet', 'Shrink wrap / strap (inbound)', null],
        'WH-LABEL-IN' => ['warehouse', 'label', 'Label (inbound)', null],
        'WH-STORAGE-PLT-WK' => ['storage', 'pallet_week', 'Storage – standard pallet', '≤ 1200 × 1200 × 1400 mm, < 800 kg'],
        'WH-STORAGE-PLT-WIDE-WK' => ['storage', 'pallet_week', 'Storage – overwidth pallet', 'one side ≤ 2400, other ≤ 1200, ≤ 1400 high'],
        'WH-STORAGE-PLT-HIGH-WK' => ['storage', 'pallet_week', 'Storage – overheight pallet', '≤ 1200 × 1200 × 1800 mm'],
        'WH-STORAGE-PICKFACE-WK' => ['storage', 'pickface_week', 'Storage – pickface', 'per occupied pickface slot per week'],
        'WH-STORAGE-PLT-OVERWEIGHT-WK' => ['storage', 'pallet_week', 'Storage – overweight pallet', 'POA (≥ 800 kg)'],
        'WH-PALLET-RENT-PLAIN-WK' => ['storage', 'pallet_week', 'Pallet rental – plain pallet', null],
        'WH-PALLET-RENT-POOL-WK' => ['storage', 'pallet_week', 'Pallet rental – CHEP / LOSCAM', null],
        'WH-ORDER-DESPATCH' => ['warehouse', 'order', 'Order process – despatch', null],
        'WH-ORDER-DESPATCH-URGENT' => ['warehouse', 'order', 'Order process – urgent despatch', 'same-day dispatch requested after the client cut-off'],
        'WH-PICK-PLT' => ['warehouse', 'pallet', 'Pick – pallet', null],
        'WH-PICK-CTN-GE45' => ['warehouse', 'carton', 'Pick – carton ≥ 45 kg', null],
        'WH-PICK-CTN-22-45' => ['warehouse', 'carton', 'Pick – carton 22–44.99 kg', null],
        'WH-PICK-CTN-LT22' => ['warehouse', 'carton', 'Pick – carton < 22 kg', null],
        'WH-LOAD-PLT' => ['warehouse', 'pallet', 'Truck load out – pallet', null],
        'WH-WRAP-OUT-PLT' => ['vas', 'pallet', 'Shrink wrap / strap (outbound)', null],
        'WH-LABEL-OUT' => ['warehouse', 'label', 'Label and despatch fee', null],
        'VAS-SCAN' => ['vas', 'scan', 'Serial number scanning', null],
        'VAS-WASTE-CBM' => ['vas', 'cbm', 'Waste disposal', 'minimum 1 CBM'],
        'VAS-PALLET-PURCHASE' => ['vas', 'pallet', 'Pallet purchase (wood / plastic)', 'warehouse-supplied pallets, once at putaway'],
        'VAS-PALLET-PURCHASE-NONSTD' => ['vas', 'pallet', 'Non-standard pallet purchase', 'manual charge with reason'],
        'VAS-LABOUR-HR' => ['vas', 'man_hour', 'Other VAS – business hours', null],
        'VAS-LABOUR-HR-AH' => ['vas', 'man_hour', 'Other VAS – outside business hours', null],
        // Codes the plan requires that have no Edward row (priced only by a client card; otherwise Missing Rate).
        'TR-DELIVERY-BASE' => ['transport', 'delivery', 'Delivery', 'own fleet fixed or third-party cost × markup'],
        'TR-TAILGATE' => ['transport', 'delivery', 'Tailgate delivery', null],
        'TR-REMOTE' => ['transport', 'delivery', 'Remote area surcharge', null],
        'TR-FUEL' => ['transport', 'delivery', 'Fuel surcharge', 'percentage of the delivery charge'],
        'TR-FAILED' => ['transport', 'delivery', 'Failed delivery', null],
        'TR-REDELIVERY' => ['transport', 'delivery', 'Re-delivery', null],
        'TR-WAITING' => ['transport', 'man_hour', 'Waiting time', null],
        'WH-STORAGE-CTN-WK' => ['storage', 'carton_week', 'Storage – loose carton', 'if the client card bills per carton'],
        'WH-STORAGE-CBM-WK' => ['storage', 'cbm_week', 'Storage – loose cartons by volume', 'if the client card bills per CBM'],
        'WH-STORAGE-QUARANTINE-PLT-WK' => ['storage', 'pallet_week', 'Storage – quarantined / damaged pallet', 'still charged, separate code (§4.8)'],
    ];

    /** code => [trigger_event, condition, quantity_source, idempotency template] */
    public const RULES = [
        'TR-CARTAGE-20' => ['shipment.quote_confirmed', ['charge_code' => 'TR-CARTAGE-20'], 'one', 'shipment:{shipment_id}'],
        'TR-CARTAGE-40' => ['shipment.quote_confirmed', ['charge_code' => 'TR-CARTAGE-40'], 'one', 'shipment:{shipment_id}'],
        'WH-DEVAN-20-PLT' => ['task.completed', ['task_type' => 'devanning', 'container.size' => '20', 'container.unpack_mode' => 'pallet'], 'billable_qty', 'task:{task_id}'],
        'WH-DEVAN-20-LOOSE' => ['task.completed', ['task_type' => 'devanning', 'container.size' => '20', 'container.unpack_mode' => 'loose'], 'billable_qty', 'task:{task_id}'],
        'WH-DEVAN-20-MIXED' => ['task.completed', ['task_type' => 'devanning', 'container.size' => '20', 'container.unpack_mode' => 'mixed'], 'billable_qty', 'task:{task_id}'],
        'WH-DEVAN-40-PLT' => ['task.completed', ['task_type' => 'devanning', 'container.size' => '40', 'container.unpack_mode' => 'pallet'], 'billable_qty', 'task:{task_id}'],
        'WH-DEVAN-40-LOOSE' => ['task.completed', ['task_type' => 'devanning', 'container.size' => '40', 'container.unpack_mode' => 'loose'], 'billable_qty', 'task:{task_id}'],
        'WH-DEVAN-40-MIXED' => ['task.completed', ['task_type' => 'devanning', 'container.size' => '40', 'container.unpack_mode' => 'mixed'], 'billable_qty', 'task:{task_id}'],
        'WH-UNLOAD-PLT' => ['task.completed', ['task_type' => 'receiving'], 'billable_qty', 'task:{task_id}'],
        'WH-PUTAWAY-PLT' => ['asn.putaway_completed', null, 'pallets', 'asn:{asn_id}'],
        'WH-WRAP-IN-PLT' => ['task.completed', ['task_type' => 'wrap', 'source_type' => ['asn', 'container']], 'billable_qty', 'task:{task_id}'],
        'WH-LABEL-IN' => ['asn.putaway_completed', null, 'labels', 'asn:{asn_id}'],
        'WH-STORAGE-PLT-WK' => ['snapshot.weekly', ['unit_type' => 'pallet', 'pallet_class' => 'standard', 'condition' => 'good'], 'weeks', 'unit:{stock_unit_id}:week:{week}'],
        'WH-STORAGE-PLT-WIDE-WK' => ['snapshot.weekly', ['unit_type' => 'pallet', 'pallet_class' => 'oversize_wide', 'condition' => 'good'], 'weeks', 'unit:{stock_unit_id}:week:{week}'],
        'WH-STORAGE-PLT-HIGH-WK' => ['snapshot.weekly', ['unit_type' => 'pallet', 'pallet_class' => 'oversize_high', 'condition' => 'good'], 'weeks', 'unit:{stock_unit_id}:week:{week}'],
        'WH-STORAGE-PICKFACE-WK' => ['snapshot.weekly', ['location_type' => 'pickface'], 'pickface_slots', 'client:{client_id}:pickface:week:{week}'],
        'WH-STORAGE-PLT-OVERWEIGHT-WK' => ['snapshot.weekly', ['unit_type' => 'pallet', 'pallet_class' => 'overweight', 'condition' => 'good'], 'weeks', 'unit:{stock_unit_id}:week:{week}'],
        'WH-PALLET-RENT-PLAIN-WK' => ['snapshot.weekly', ['unit_type' => 'pallet', 'pallet_source' => 'warehouse_plain'], 'weeks', 'unit:{stock_unit_id}:week:{week}'],
        'WH-PALLET-RENT-POOL-WK' => ['snapshot.weekly', ['unit_type' => 'pallet', 'pallet_source' => ['chep', 'loscam']], 'weeks', 'unit:{stock_unit_id}:week:{week}'],
        'WH-ORDER-DESPATCH' => ['outbound.packed', ['is_urgent' => false], 'orders', 'fulfilment:{fulfilment_id}'],
        'WH-ORDER-DESPATCH-URGENT' => ['outbound.packed', ['is_urgent' => true], 'orders', 'fulfilment:{fulfilment_id}'],
        'WH-PICK-PLT' => ['outbound.packed', null, 'pallets', 'fulfilment:{fulfilment_id}'],
        'WH-PICK-CTN-GE45' => ['outbound.packed', null, 'cartons', 'fulfilment:{fulfilment_id}'],
        'WH-PICK-CTN-22-45' => ['outbound.packed', null, 'cartons', 'fulfilment:{fulfilment_id}'],
        'WH-PICK-CTN-LT22' => ['outbound.packed', null, 'cartons', 'fulfilment:{fulfilment_id}'],
        'WH-LOAD-PLT' => ['task.completed', ['task_type' => 'load'], 'billable_qty', 'task:{task_id}'],
        'WH-WRAP-OUT-PLT' => ['task.completed', ['task_type' => 'wrap', 'source_type' => ['order', 'fulfilment']], 'billable_qty', 'task:{task_id}'],
        'WH-LABEL-OUT' => ['outbound.packed', null, 'labels', 'fulfilment:{fulfilment_id}'],
        'VAS-SCAN' => ['task.completed', ['task_type' => 'scanning'], 'scans', 'task:{task_id}'],
        'VAS-WASTE-CBM' => ['task.completed', ['task_type' => 'waste'], 'billable_qty', 'task:{task_id}'],
        'VAS-PALLET-PURCHASE' => ['asn.putaway_completed', null, 'pallets_warehouse_plain', 'asn:{asn_id}'],
        'VAS-PALLET-PURCHASE-NONSTD' => ['manual', null, 'one', 'manual:{charge_id}'],
        'VAS-LABOUR-HR' => ['task.completed', ['task_type' => ['labour', 'vas_other']], 'hours_business', 'task:{task_id}'],
        'VAS-LABOUR-HR-AH' => ['task.completed', ['task_type' => ['labour', 'vas_other']], 'hours_after_hours', 'task:{task_id}'],
        'TR-DELIVERY-BASE' => ['shipment.quote_confirmed', ['charge_code' => 'TR-DELIVERY-BASE'], 'one', 'shipment:{shipment_id}'],
        'TR-TAILGATE' => ['shipment.quote_confirmed', ['tailgate_required' => true], 'one', 'shipment:{shipment_id}'],
        'TR-REMOTE' => ['shipment.quote_confirmed', ['zone' => 'remote'], 'one', 'shipment:{shipment_id}'],
        'TR-FUEL' => ['shipment.quote_confirmed', ['charge_code' => 'TR-DELIVERY-BASE'], 'one', 'shipment:{shipment_id}'],
        'TR-FAILED' => ['delivery.extra_charge', ['charge_type' => 'failed_delivery'], 'one', 'extra:{delivery_id}:{charge_type}'],
        'TR-REDELIVERY' => ['delivery.extra_charge', ['charge_type' => 'redelivery'], 'one', 'extra:{delivery_id}:{charge_type}'],
        'TR-WAITING' => ['delivery.extra_charge', ['charge_type' => 'waiting'], 'hours_business', 'extra:{delivery_id}:{charge_type}'],
        'WH-STORAGE-CTN-WK' => ['snapshot.weekly', ['unit_type' => 'carton', 'condition' => 'good'], 'cartons', 'unit:{stock_unit_id}:week:{week}'],
        'WH-STORAGE-CBM-WK' => ['snapshot.weekly', ['unit_type' => 'carton', 'condition' => 'good'], 'cbm', 'unit:{stock_unit_id}:week:{week}'],
        'WH-STORAGE-QUARANTINE-PLT-WK' => ['snapshot.weekly', ['unit_type' => 'pallet', 'condition' => ['quarantine', 'damaged']], 'weeks', 'unit:{stock_unit_id}:week:{week}'],
    ];

    /** Edward 2026-02-27: code => [rate_cents|null(POA), extra rate_item attributes]. 34 rows. */
    public const EDWARD_ITEMS = [
        'TR-CARTAGE-20' => [123060, ['threshold_json' => ['max_gross_weight_kg' => 22500]]],
        'TR-CARTAGE-40' => [129160, ['threshold_json' => ['max_gross_weight_kg' => 22500]]],
        'WH-DEVAN-20-PLT' => [18000, []],
        'WH-DEVAN-20-LOOSE' => [40000, ['threshold_json' => ['max_line_count' => 20]]],
        'WH-DEVAN-20-MIXED' => [null, ['is_poa' => true, 'notes' => '$300 subject to container']],
        'WH-DEVAN-40-PLT' => [28000, []],
        'WH-DEVAN-40-LOOSE' => [55000, ['threshold_json' => ['max_line_count' => 20]]],
        'WH-DEVAN-40-MIXED' => [null, ['is_poa' => true, 'notes' => '$450 subject to container']],
        'WH-UNLOAD-PLT' => [400, []],
        'WH-PUTAWAY-PLT' => [450, []],
        'WH-WRAP-IN-PLT' => [450, []],
        'WH-LABEL-IN' => [30, []],
        'WH-STORAGE-PLT-WK' => [450, ['pallet_class' => 'standard', 'threshold_json' => ['max_length_mm' => 1200, 'max_width_mm' => 1200, 'max_height_mm' => 1400, 'max_weight_kg' => 800]]],
        'WH-STORAGE-PLT-WIDE-WK' => [900, ['pallet_class' => 'oversize_wide', 'threshold_json' => ['max_long_side_mm' => 2400, 'max_short_side_mm' => 1200, 'max_height_mm' => 1400, 'max_weight_kg' => 800]]],
        'WH-STORAGE-PLT-HIGH-WK' => [800, ['pallet_class' => 'oversize_high', 'threshold_json' => ['max_length_mm' => 1200, 'max_width_mm' => 1200, 'max_height_mm' => 1800, 'max_weight_kg' => 800]]],
        'WH-STORAGE-PICKFACE-WK' => [650, ['pallet_class' => 'pickface', 'threshold_json' => ['slot_length_mm' => 2650, 'slot_width_mm' => 1000, 'slot_height_mm' => 600]]],
        'WH-STORAGE-PLT-OVERWEIGHT-WK' => [null, ['pallet_class' => 'overweight', 'is_poa' => true, 'threshold_json' => ['min_weight_kg' => 800]]],
        'WH-PALLET-RENT-PLAIN-WK' => [70, []],
        'WH-PALLET-RENT-POOL-WK' => [200, []],
        'WH-ORDER-DESPATCH' => [500, []],
        'WH-ORDER-DESPATCH-URGENT' => [1500, ['threshold_json' => ['cutoff_source' => 'clients.dispatch_cutoff_time']]],
        'WH-PICK-PLT' => [400, []],
        'WH-PICK-CTN-GE45' => [450, ['weight_band_min' => 45.00, 'weight_band_max' => null]],
        'WH-PICK-CTN-22-45' => [350, ['weight_band_min' => 22.00, 'weight_band_max' => 44.99]],
        'WH-PICK-CTN-LT22' => [150, ['weight_band_min' => 0.00, 'weight_band_max' => 21.99]],
        'WH-LOAD-PLT' => [400, []],
        'WH-WRAP-OUT-PLT' => [450, []],
        'WH-LABEL-OUT' => [30, []],
        'VAS-SCAN' => [50, []],
        'VAS-WASTE-CBM' => [8000, ['threshold_json' => ['min_billable_qty' => 1]]],
        'VAS-PALLET-PURCHASE' => [2500, []],
        'VAS-PALLET-PURCHASE-NONSTD' => [1500, []],
        'VAS-LABOUR-HR' => [4000, []],
        'VAS-LABOUR-HR-AH' => [5500, []],
    ];

    public function run(): void
    {
        foreach (self::CODES as $code => [$category, $uom, $customer, $internal]) {
            ChargeCode::query()->updateOrCreate(['code' => $code], ['category' => $category, 'default_uom' => $uom, 'customer_description' => $customer, 'internal_description' => $internal, 'tax_treatment' => 'gst_10', 'active' => true]);
        }
        $codes = ChargeCode::query()->pluck('id', 'code');

        foreach (self::RULES as $code => [$event, $condition, $qtySource, $template]) {
            ChargeRule::query()->updateOrCreate(
                ['trigger_event' => $event, 'charge_code_id' => $codes[$code]],
                ['condition' => $condition, 'quantity_source' => $qtySource, 'rate_match_priority' => 'client_then_standard', 'idempotency_key_template' => $template, 'active' => true],
            );
        }

        $card = RateCard::query()->firstOrCreate(
            ['is_standard' => true, 'version' => 1],
            ['client_id' => null, 'name' => 'Edward standard rate card 2026-02-27', 'currency' => 'AUD', 'effective_from' => '2026-02-27', 'status' => 'active', 'notes' => 'Seeded from Edward Storage rate 27022026.xlsx (ex GST)'],
        );
        if ($card->items()->count() === 0) {
            foreach (self::EDWARD_ITEMS as $code => [$rateCents, $extra]) {
                RateItem::query()->create($extra + ['rate_card_id' => $card->id, 'charge_code_id' => $codes[$code], 'pricing_mode' => 'fixed', 'rate_cents' => $rateCents, 'is_poa' => $extra['is_poa'] ?? false]);
            }
        }

        // Every client is bound to the standard card unless Finance unbinds it (§6.3 rate_cards.is_standard).
        Client::query()->withoutGlobalScopes()->whereNull('standard_rate_card_id')->update(['standard_rate_card_id' => $card->id]);
    }
}
