<?php

namespace App\Modules\Orders\Services;

use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\CustomerQuote;
use App\Modules\Billing\Models\CustomerQuoteLine;
use App\Modules\Billing\Services\QuoteService;
use App\Modules\Orders\Exceptions\OrderRuleViolation;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Support\Contracts\RateService;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A7b / OMS-3 (ERP_PLAN §3.9 Q3 "定价 = 服务费预估"): estimate what an order will cost the client before it moves.
 *
 * Warehouse service fees are priced through Billing's RateService and persisted as a customer quote through Billing's
 * QuoteService (CHANGE_REQUESTS #10 — customer_quotes belong to Billing; Orders only keeps `orders.customer_quote_id`).
 * Freight is Transport's business: `order.confirmed` makes Transport write preliminary `transport_quotes`; this service
 * reads them read-only (customer price only — never cost), takes the recommended / cheapest one and stores it on the quote
 * as a pre-priced `TR-DELIVERY-BASE` line (`amount_cents` + `transport_quote_id`, CHANGE_REQUESTS #50 → #68) so the customer
 * quote is a complete snapshot. POA and missing rates stay flagged ("待报价"), never $0. Assumptions: CHANGE_REQUESTS #51, #73.
 */
final class OrderEstimateService
{
    /** Staff roles allowed to price an order; client users price their own orders through the portal. */
    public const STAFF_ROLES = ['admin', 'customer_service', 'dispatcher', 'finance'];

    /** Order statuses in which an estimate still makes sense (after dispatch the final quote / invoice takes over). */
    public const ESTIMABLE_STATUSES = ['received', 'confirmed', 'allocated', 'picking', 'packed'];

    /** The freight line's charge code (charge-codes.md #TR-DELIVERY-BASE): pre-priced from Transport's customer price, never re-priced from a card. */
    public const FREIGHT_CODE = 'TR-DELIVERY-BASE';

    /** Carton pick bands, lightest first; the edges live on the rate items (weight_band_min/max), these are the contract defaults used only when no item matches. */
    private const CARTON_PICK_CODES = [['WH-PICK-CTN-LT22', 0.0, 22.0], ['WH-PICK-CTN-22-45', 22.0, 45.0], ['WH-PICK-CTN-GE45', 45.0, null]];

    public function __construct(
        private readonly QuoteService $quotes,
        private readonly RateService $rates,
        private readonly OrderStatusService $statuses,
    ) {}

    public function canEstimate(Order $order): bool
    {
        return $order->order_type !== 'return' && in_array($order->operational_status, self::ESTIMABLE_STATUSES, true);
    }

    /**
     * Price the order's expected warehouse services and persist them as a preliminary customer quote. A re-estimate creates a
     * new quote, marks the previous one `expired` through QuoteService and moves `orders.customer_quote_id` to the new one.
     */
    public function estimate(Order $order, ?int $actorId): CustomerQuote
    {
        if (! $this->canEstimate($order)) {
            throw new OrderRuleViolation(__('orders.estimate.messages.not_estimable'));
        }

        $order->loadMissing('lines', 'declaredPackages', 'client', 'job');
        $lines = $this->lines($order);
        $freight = $this->freight($order);
        if ($freight !== null) {
            $lines[] = $this->freightLine($freight); // CHANGE_REQUESTS #68: Transport's customer price stored as given, never re-priced
        }

        return DB::transaction(function () use ($order, $lines, $freight, $actorId): CustomerQuote {
            $previous = $order->customer_quote_id ? CustomerQuote::query()->find($order->customer_quote_id) : null;

            $quote = $this->quotes->create($order->client_id, $lines, [
                'job_id' => $order->job_id,
                'order_id' => $order->id,
                'stage' => 'preliminary',
                'notes' => $freight === null ? __('orders.estimate.notes.freight_pending') : null, // with a freight line the quote is self-contained
            ]);

            if ($previous !== null && $previous->id !== $quote->id) {
                $this->quotes->setStatus($previous, 'expired'); // superseded: the old reference stays for audit, the order points at the new quote
            }
            $order->update(['customer_quote_id' => $quote->id]);
            // An all-unpriced quote (POA / missing rates) is reported as 待报价 in the timeline too — never as $0.00.
            $services = (int) $quote->subtotal_cents - ($freight['customer_price_cents'] ?? 0);
            $unpriced = $services === 0 && $quote->lines()->whereNull('transport_quote_id')->exists();
            $this->statuses->note($order, $actorId, __('orders.estimate.timeline.created', ['quote_no' => $quote->quote_no, 'total' => $unpriced ? __('orders.estimate.flags.missing') : Money::cents($services)->format()]));

            return $quote;
        });
    }

    /**
     * Tester feedback #10: price an order that is not saved yet (portal "获取估价" before "确认提交"). Same lines as estimate(),
     * priced through RateService, nothing written. Freight cannot exist yet (Transport quotes after confirmation).
     *
     * @return array{lines: list<array{charge_code:string, description:string, qty:float, uom:?string, amount_cents:?int, missing:bool}>, subtotal_cents:int, gst_cents:int, total_cents:int, unpriced:bool}
     */
    public function preview(Order $order): array
    {
        $rows = [];
        $subtotal = 0;
        $gst = 0;
        $unpriced = false;
        foreach ($this->lines($order) as $line) {
            $code = ChargeCode::query()->where('code', $line['charge_code'])->first();
            $priced = $this->rates->price($order->client_id, $line['charge_code'], (float) $line['qty'], $line['context'] ?? []);
            $amount = $priced['missing_rate'] || $priced['is_poa'] ? null : $priced['amount_cents'];
            if ($amount === null) {
                $unpriced = true;
            } else {
                $subtotal += $amount;
                $gst += $code && $code->gstRate() > 0 ? (int) round($amount * $code->gstRate()) : 0;
            }
            $rows[] = ['charge_code' => $line['charge_code'], 'description' => $line['description'] ?? ($code?->customer_description ?? $line['charge_code']), 'qty' => (float) ($priced['qty'] ?? $line['qty']), 'uom' => $priced['uom'] ?? $code?->default_uom, 'amount_cents' => $amount, 'missing' => $amount === null];
        }

        return ['lines' => $rows, 'subtotal_cents' => $subtotal, 'gst_cents' => $gst, 'total_cents' => $subtotal + $gst, 'unpriced' => $unpriced];
    }

    /**
     * The quote lines for QuoteService: order processing (+ urgent), picks per unit, outbound labels, load-out.
     *
     * @return list<array{charge_code:string, qty:float, context:array<string, mixed>, description?:string}>
     */
    public function lines(Order $order): array
    {
        $order->loadMissing('lines', 'client');
        if ($order->order_type === 'pickup_deliver') {
            return []; // pure transport: no warehouse handling, the estimate is the freight quote alone (A11b)
        }

        $urgent = $this->isUrgent($order);
        $lines = [['charge_code' => 'WH-ORDER-DESPATCH', 'qty' => 1.0, 'context' => ['is_urgent' => $urgent]]];
        if ($urgent) {
            $lines[] = ['charge_code' => 'WH-ORDER-DESPATCH-URGENT', 'qty' => 1.0, 'context' => ['is_urgent' => true]];
        }

        $pallets = 0;
        $pieces = 0;
        foreach ($order->lines as $line) {
            $unit = $this->pickUnit($line);
            $pieces += $unit['qty'];
            if ($unit['unit_type'] === 'pallet') {
                $pallets += $unit['qty'];
                $lines[] = ['charge_code' => 'WH-PICK-PLT', 'qty' => (float) $unit['qty'], 'context' => ['order_line_id' => $line->id, 'unit_source' => $unit['source']], 'description' => __('orders.estimate.descriptions.pick_pallet', ['goods' => $this->goods($line)])];

                continue;
            }
            $weight = $unit['weight_kg'];
            $lines[] = [
                'charge_code' => $this->cartonPickCode($order->client_id, $weight ?? 0.0),
                'qty' => (float) $unit['qty'],
                'context' => ['weight_kg' => $weight ?? 0.0, 'weight_assumed' => $weight === null, 'order_line_id' => $line->id, 'unit_source' => $unit['source']],
                'description' => __($weight === null ? 'orders.estimate.descriptions.pick_carton_unknown' : 'orders.estimate.descriptions.pick_carton', ['goods' => $this->goods($line), 'weight' => number_format((float) $weight, 2)]),
            ];
        }

        if ($pieces > 0) {
            $lines[] = ['charge_code' => 'WH-LABEL-OUT', 'qty' => (float) $pieces, 'context' => []];
        }
        if ($pallets > 0) {
            $lines[] = ['charge_code' => 'WH-LOAD-PLT', 'qty' => (float) $pallets, 'context' => []];
        }

        return $lines;
    }

    /**
     * Transport's preliminary freight quote for the order (final for pure transport orders, which are priced final at
     * confirmation): recommended first, else cheapest, else the lowest customer price. Read-only on X2's tables; cost is
     * never selected so it can never reach a client user.
     *
     * @return array{transport_quote_id:int, shipment_no:string, carrier_name:?string, source:string, service_level:string, customer_price_cents:int, eta_days:?int, is_recommended:bool, is_cheapest:bool, is_fastest:bool, quote_stage:string, quoted_at:?string, label:string}|null
     */
    public function freight(Order $order): ?array
    {
        $stage = $order->order_type === 'pickup_deliver' ? 'final' : 'preliminary';
        $row = $this->transportQuotes()
            ->where('s.order_id', $order->id)
            ->where('s.client_id', $order->client_id)
            ->where('s.shipment_type', 'outbound')
            ->where('q.quote_stage', $stage)
            ->whereIn('q.status', ['quoted', 'selected'])
            ->orderByDesc('q.status') // a selected quote beats a merely quoted one
            ->orderByDesc('q.is_recommended')
            ->orderByDesc('q.is_cheapest')
            ->orderBy('q.customer_price_cents')
            ->orderByDesc('q.id')
            ->first(self::FREIGHT_COLUMNS);

        return $row === null ? $this->freightFromPreference($order) : $this->freightRow($row);
    }

    /**
     * CHANGE_REQUESTS #118: no Transport quote yet (the order is not confirmed) — the option the client chose with the 估价 is the
     * freight, as the snapshot the client saw (customer price only). Read back through freightFromLine() as the stored line.
     */
    private function freightFromPreference(Order $order): ?array
    {
        $p = $order->transport_preference;
        if (! is_array($p) || ! isset($p['source'], $p['service_level'], $p['customer_price_cents'])) {
            return null;
        }
        $freight = [
            'transport_quote_id' => null,
            'shipment_no' => '',
            'carrier_name' => $p['carrier_name'] ?? null,
            'source' => (string) $p['source'],
            'service_level' => (string) $p['service_level'],
            'customer_price_cents' => (int) $p['customer_price_cents'],
            'eta_days' => isset($p['eta_days']) ? (int) $p['eta_days'] : null,
            'is_recommended' => (bool) ($p['is_recommended'] ?? false),
            'is_cheapest' => (bool) ($p['is_cheapest'] ?? false),
            'is_fastest' => (bool) ($p['is_fastest'] ?? false),
            'quote_stage' => 'preliminary',
            'quoted_at' => $p['chosen_at'] ?? null,
            'client_choice' => true,
        ];
        $freight['label'] = $this->freightLabel($freight);

        return $freight;
    }

    /**
     * The pre-priced QuoteService line for Transport's freight (CHANGE_REQUESTS #68): the customer price as given, the
     * transport quote referenced, the carrier / service level in the description.
     *
     * @param  array{transport_quote_id:?int, customer_price_cents:int, label:string, quote_stage:string, shipment_no:string}  $freight
     * @return array{charge_code:string, qty:float, amount_cents:int, transport_quote_id:?int, description:string, context:array<string, mixed>}
     */
    public function freightLine(array $freight): array
    {
        return [
            'charge_code' => self::FREIGHT_CODE,
            'qty' => 1.0,
            'amount_cents' => $freight['customer_price_cents'],
            'transport_quote_id' => $freight['transport_quote_id'],
            'description' => __($freight['transport_quote_id'] === null ? 'orders.estimate.descriptions.freight_preference' : 'orders.estimate.descriptions.freight', ['label' => $freight['label']]), // CHANGE_REQUESTS #118: the client's own choice with the 估价 until Transport quotes
            'context' => ['quote_stage' => $freight['quote_stage'], 'shipment_no' => $freight['shipment_no']],
        ];
    }

    /**
     * The stored freight line read back as the estimate's freight (a snapshot: the amount is the line's, the carrier /
     * flags come from the referenced transport quote — customer columns only).
     *
     * @return array{transport_quote_id:?int, shipment_no:string, carrier_name:?string, source:string, service_level:string, customer_price_cents:int, eta_days:?int, is_recommended:bool, is_cheapest:bool, is_fastest:bool, quote_stage:string, quoted_at:?string, label:string}
     */
    private function freightFromLine(CustomerQuoteLine $line): array
    {
        $row = $line->transport_quote_id === null ? null : $this->transportQuotes()->where('q.id', $line->transport_quote_id)->first(self::FREIGHT_COLUMNS);
        if ($row !== null) {
            return ['customer_price_cents' => (int) $line->amount_cents] + $this->freightRow($row);
        }

        // The transport quote row is gone (re-quoted / purged): the line still tells the story.
        return [
            'transport_quote_id' => $line->transport_quote_id === null ? null : (int) $line->transport_quote_id,
            'shipment_no' => (string) ($line->assumptions['shipment_no'] ?? ''),
            'carrier_name' => null, 'source' => 'manual', 'service_level' => 'standard',
            'customer_price_cents' => (int) $line->amount_cents, 'eta_days' => null,
            'is_recommended' => false, 'is_cheapest' => false, 'is_fastest' => false,
            'quote_stage' => (string) ($line->assumptions['quote_stage'] ?? 'preliminary'), 'quoted_at' => null,
            'label' => (string) $line->description,
        ];
    }

    /** Customer-visible quote columns (never cost_cents / markup_percent). */
    private const FREIGHT_COLUMNS = ['q.id', 's.shipment_no', 'c.name as carrier_name', 'q.source', 'q.service_level', 'q.customer_price_cents', 'q.eta_days', 'q.is_recommended', 'q.is_cheapest', 'q.is_fastest', 'q.quote_stage', 'q.quoted_at'];

    private function transportQuotes(): Builder
    {
        return DB::table('transport_quotes as q')
            ->join('shipments as s', 's.id', '=', 'q.shipment_id')
            ->leftJoin('carriers as c', 'c.id', '=', 'q.carrier_id');
    }

    /** @return array{transport_quote_id:int, shipment_no:string, carrier_name:?string, source:string, service_level:string, customer_price_cents:int, eta_days:?int, is_recommended:bool, is_cheapest:bool, is_fastest:bool, quote_stage:string, quoted_at:?string, label:string} */
    private function freightRow(object $row): array
    {
        $freight = [
            'transport_quote_id' => (int) $row->id,
            'shipment_no' => (string) $row->shipment_no,
            'carrier_name' => $row->carrier_name,
            'source' => (string) $row->source,
            'service_level' => (string) $row->service_level,
            'customer_price_cents' => (int) $row->customer_price_cents,
            'eta_days' => $row->eta_days === null ? null : (int) $row->eta_days,
            'is_recommended' => (bool) $row->is_recommended,
            'is_cheapest' => (bool) $row->is_cheapest,
            'is_fastest' => (bool) $row->is_fastest,
            'quote_stage' => (string) $row->quote_stage,
            'quoted_at' => $row->quoted_at === null ? null : (string) $row->quoted_at,
        ];
        $freight['label'] = $this->freightLabel($freight);

        return $freight;
    }

    /**
     * The estimate as shown to staff and clients: the persisted quote's service lines (客户价 only — `assumptions` carry
     * pricing context and are never rendered), its freight line as the freight snapshot and the totals. Unpriced lines
     * (POA / missing rate) are flagged and excluded from the totals instead of counting as $0.
     *
     * @return array{quote: CustomerQuote, lines: list<array{description:string, charge_code:string, qty:string, uom:string, amount_cents:?int, flag:?string, weight_assumed:bool}>, unpriced:int, subtotal_cents:int, freight:?array, total_cents:?int, gst_cents:int, total_inc_gst_cents:?int}|null
     */
    public function current(Order $order): ?array
    {
        if ($order->customer_quote_id === null) {
            return null;
        }
        $quote = CustomerQuote::query()->with('lines')->find($order->customer_quote_id);
        if ($quote === null) {
            return null;
        }

        $isFreight = fn (CustomerQuoteLine $line): bool => $line->transport_quote_id !== null || $line->charge_code === self::FREIGHT_CODE;
        $freightLine = $quote->lines->first($isFreight);
        $unpriced = 0;
        $lines = $quote->lines->reject($isFreight)->map(function (CustomerQuoteLine $line) use (&$unpriced): array {
            $flag = match (true) {
                (bool) ($line->assumptions['is_poa'] ?? false) => 'poa',
                (bool) ($line->assumptions['missing_rate'] ?? false) => 'missing',
                default => null,
            };
            if ($flag !== null) {
                $unpriced++;
            }

            return [
                'description' => (string) $line->description,
                'charge_code' => (string) $line->charge_code,
                'qty' => rtrim(rtrim((string) $line->qty, '0'), '.'),
                'uom' => (string) $line->uom,
                'amount_cents' => $flag === null ? (int) $line->amount_cents : null,
                'flag' => $flag,
                'weight_assumed' => (bool) ($line->assumptions['weight_assumed'] ?? false),
            ];
        })->values()->all();

        $freight = $freightLine === null ? null : $this->freightFromLine($freightLine);
        $services = (int) $quote->subtotal_cents - ($freightLine === null ? 0 : (int) $freightLine->amount_cents);
        $priced = collect($lines)->whereNull('flag')->isNotEmpty() || $freight !== null;

        return [
            'quote' => $quote,
            'lines' => $lines,
            'unpriced' => $unpriced,
            'subtotal_cents' => $services,                       // warehouse services, ex GST
            'freight' => $freight,                               // the stored freight line (snapshot), null = 待运输报价
            // Totals are only meaningful when something was priced: all-unpriced quotes show 待报价, never $0.
            'total_cents' => $priced ? (int) $quote->subtotal_cents : null,          // ex GST, incl. freight
            'gst_cents' => (int) $quote->gst_cents,
            'total_inc_gst_cents' => $priced ? (int) $quote->total_cents : null,
        ];
    }

    /**
     * Urgent = the same predicate Warehouse applies at packing (`outbound.packed.is_urgent`, OutboundService::pack): the client
     * has a dispatch cut-off, dispatch is requested for today, and the clock is already past the cut-off. No cut-off → never
     * urgent (review of PR #18, CHANGE_REQUESTS #75). The estimate can only look at "now"; Warehouse decides at pack time.
     */
    public function isUrgent(Order $order): bool
    {
        $cutoff = $order->client?->dispatch_cutoff_time;
        if (blank($cutoff) || $order->requested_date === null) {
            return false;
        }

        return $order->requested_date->toDateString() === today()->toDateString() && now()->format('H:i:s') > substr((string) $cutoff, 0, 8);
    }

    /**
     * How a goods line will be picked. Stock units linked through the ASN line decide (pallet units → pallet picks, cartons
     * per pallet from the units); without stock the line's package_type decides (pallet-like → pallets, else cartons). The
     * per-carton weight is the line weight over its cartons (a line's actual_weight_kg is the line total, as in TailgateRule),
     * falling back to the ASN line; null = unknown, banded as the lightest and flagged.
     *
     * @return array{unit_type:string, qty:int, weight_kg:?float, source:string}
     */
    private function pickUnit(OrderLine $line): array
    {
        $cartons = max(1, (int) $line->carton_qty);
        $weight = $line->actual_weight_kg === null ? null : (float) $line->actual_weight_kg / $cartons;

        if ($line->asn_line_id !== null) {
            // Capacity comes from what the pallets held when received (emptied pallets must not shrink the divisor — review of PR #18, CHANGE_REQUESTS #75).
            $units = DB::table('stock_units')->where('asn_line_id', $line->asn_line_id)->where('putaway_completed', true)->where('condition', 'good')
                ->selectRaw('unit_type, count(*) as units, coalesce(sum(qty_on_hand), 0) as cartons, coalesce(sum(case when qty_on_hand > 0 then qty_on_hand end), 0) as cartons_stocked, sum(case when qty_on_hand > 0 then 1 else 0 end) as units_stocked')->groupBy('unit_type')->get()->keyBy('unit_type');
            if ($weight === null) {
                $asnLine = DB::table('asn_lines')->where('id', $line->asn_line_id)->first(['weight_kg', 'received_cartons', 'expected_cartons']);
                $asnCartons = (int) ($asnLine?->received_cartons ?: $asnLine?->expected_cartons ?: 0);
                $weight = $asnLine?->weight_kg !== null && $asnCartons > 0 ? (float) $asnLine->weight_kg / $asnCartons : null;
            }
            if (isset($units['pallet'])) {
                $received = (int) (DB::table('asn_lines')->where('id', $line->asn_line_id)->value('received_cartons') ?? 0);
                $perPallet = $received > 0
                    ? max(1.0, $received / max(1, (int) $units['pallet']->units))                                                          // cartons the pallets came in with
                    : max(1.0, (float) $units['pallet']->cartons_stocked / max(1, (int) $units['pallet']->units_stocked));               // fallback: average of pallets still holding stock

                return ['unit_type' => 'pallet', 'qty' => (int) ceil($cartons / $perPallet), 'weight_kg' => $weight, 'source' => 'stock_units'];
            }
            if (isset($units['carton'])) {
                return ['unit_type' => 'carton', 'qty' => $cartons, 'weight_kg' => $weight, 'source' => 'stock_units'];
            }
        }

        if (preg_match('/pallet|plt|skid|托|栈板/iu', (string) $line->package_type) === 1) { // pallet-like package types (skid = 栈板, OrderEnums::PACKAGE_TYPES)
            return ['unit_type' => 'pallet', 'qty' => $cartons, 'weight_kg' => $weight, 'source' => 'package_type'];
        }

        return ['unit_type' => 'carton', 'qty' => $cartons, 'weight_kg' => $weight, 'source' => 'package_type'];
    }

    /** The carton pick code whose rate-item weight band holds this weight (client card → standard card); contract defaults when no band matches. */
    private function cartonPickCode(int $clientId, float $weightKg): string
    {
        foreach (self::CARTON_PICK_CODES as [$code]) {
            $priced = $this->rates->price($clientId, $code, 1.0, ['weight_kg' => $weightKg]);
            if (! $priced['missing_rate']) {
                return $code;
            }
        }
        foreach (self::CARTON_PICK_CODES as [$code, $min, $max]) {
            if ($weightKg >= $min && ($max === null || $weightKg < $max)) {
                return $code;
            }
        }

        return self::CARTON_PICK_CODES[0][0];
    }

    private function goods(OrderLine $line): string
    {
        return (string) ($line->description_cn ?: $line->description_en ?: $line->package_type);
    }

    /** @param array{carrier_name:?string, source:string, service_level:string} $freight */
    public function freightLabel(array $freight): string
    {
        return ($freight['carrier_name'] ?: __('orders.estimate.sources.'.$freight['source'])).' · '.__('orders.service_levels.'.$freight['service_level']);
    }
}
