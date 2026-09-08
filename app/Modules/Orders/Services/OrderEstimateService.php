<?php

namespace App\Modules\Orders\Services;

use App\Modules\Billing\Models\CustomerQuote;
use App\Modules\Billing\Services\QuoteService;
use App\Modules\Orders\Exceptions\OrderRuleViolation;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Support\Contracts\RateService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * A7b / OMS-3 (ERP_PLAN §3.9 Q3 "定价 = 服务费预估"): estimate what an order will cost the client before it moves.
 *
 * Warehouse service fees are priced through Billing's RateService and persisted as a customer quote through Billing's
 * QuoteService (CHANGE_REQUESTS #10 — customer_quotes belong to Billing; Orders only keeps `orders.customer_quote_id`).
 * Freight is Transport's business: `order.confirmed` makes Transport write preliminary `transport_quotes`; this service
 * reads them read-only (customer price only — never cost) and shows the recommended / cheapest one. POA and missing rates
 * stay flagged ("待报价"), never $0. Assumptions are listed in CHANGE_REQUESTS #51.
 */
final class OrderEstimateService
{
    /** Staff roles allowed to price an order; client users price their own orders through the portal. */
    public const STAFF_ROLES = ['admin', 'customer_service', 'dispatcher', 'finance'];

    /** Order statuses in which an estimate still makes sense (after dispatch the final quote / invoice takes over). */
    public const ESTIMABLE_STATUSES = ['received', 'confirmed', 'allocated', 'picking', 'packed'];

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

        return DB::transaction(function () use ($order, $lines, $freight, $actorId): CustomerQuote {
            $previous = $order->customer_quote_id ? CustomerQuote::query()->find($order->customer_quote_id) : null;

            $quote = $this->quotes->create($order->client_id, $lines, [
                'job_id' => $order->job_id,
                'order_id' => $order->id,
                'stage' => 'preliminary',
                'notes' => $freight === null
                    ? __('orders.estimate.notes.freight_pending')
                    : __('orders.estimate.notes.freight', ['carrier' => $this->freightLabel($freight), 'price' => Money::cents($freight['customer_price_cents'])->format(), 'id' => $freight['transport_quote_id']]),
            ]);

            if ($previous !== null && $previous->id !== $quote->id) {
                $this->quotes->setStatus($previous, 'expired'); // superseded: the old reference stays for audit, the order points at the new quote
            }
            $order->update(['customer_quote_id' => $quote->id]);
            // An all-unpriced quote (POA / missing rates) is reported as 待报价 in the timeline too — never as $0.00.
            $unpriced = $quote->subtotal_cents === 0 && $quote->lines()->exists();
            $this->statuses->note($order, $actorId, __('orders.estimate.timeline.created', ['quote_no' => $quote->quote_no, 'total' => $unpriced ? __('orders.estimate.flags.missing') : Money::cents($quote->subtotal_cents)->format()]));

            return $quote;
        });
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
     * @return array{transport_quote_id:int, shipment_no:string, carrier_name:?string, source:string, service_level:string, customer_price_cents:int, eta_days:?int, is_recommended:bool, is_cheapest:bool, is_fastest:bool, quote_stage:string, quoted_at:?string}|null
     */
    public function freight(Order $order): ?array
    {
        $stage = $order->order_type === 'pickup_deliver' ? 'final' : 'preliminary';
        $row = DB::table('transport_quotes as q')
            ->join('shipments as s', 's.id', '=', 'q.shipment_id')
            ->leftJoin('carriers as c', 'c.id', '=', 'q.carrier_id')
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
            ->first(['q.id', 's.shipment_no', 'c.name as carrier_name', 'q.source', 'q.service_level', 'q.customer_price_cents', 'q.eta_days', 'q.is_recommended', 'q.is_cheapest', 'q.is_fastest', 'q.quote_stage', 'q.quoted_at']);

        if ($row === null) {
            return null;
        }

        return [
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
    }

    /**
     * The estimate as shown to staff and clients: the persisted quote's lines (客户价 only — `assumptions` carry pricing
     * context and are never rendered), the live freight estimate and the totals. Unpriced lines (POA / missing rate) are
     * flagged and excluded from the totals instead of counting as $0.
     *
     * @return array{quote: CustomerQuote, lines: list<array{description:string, charge_code:string, qty:string, uom:string, amount_cents:?int, flag:?string}>, unpriced:int, subtotal_cents:int, gst_cents:int, freight:?array, total_cents:?int}|null
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

        $unpriced = 0;
        $lines = $quote->lines->map(function ($line) use (&$unpriced): array {
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

        $freight = $this->freight($order);
        $priced = collect($lines)->whereNull('flag')->isNotEmpty();

        return [
            'quote' => $quote,
            'lines' => $lines,
            'unpriced' => $unpriced,
            'subtotal_cents' => (int) $quote->subtotal_cents,
            'gst_cents' => (int) $quote->gst_cents,
            'freight' => $freight,
            // The grand total (ex GST) is only meaningful when something was priced: all-unpriced quotes show 待报价, never $0.
            'total_cents' => $priced || $freight !== null ? (int) $quote->subtotal_cents + ($freight['customer_price_cents'] ?? 0) : null,
        ];
    }

    /** Urgent = same-day dispatch requested after the client's cut-off (mirrors Warehouse's `outbound.packed.is_urgent`; without a cut-off every same-day order is urgent). */
    public function isUrgent(Order $order): bool
    {
        if ($order->service_level !== 'same_day') {
            return false;
        }
        $cutoff = $order->client?->dispatch_cutoff_time;
        if (blank($cutoff)) {
            return true;
        }

        return $order->created_at->format('H:i:s') > substr((string) $cutoff, 0, 8);
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
            $units = DB::table('stock_units')->where('asn_line_id', $line->asn_line_id)->where('putaway_completed', true)->where('condition', 'good')
                ->selectRaw('unit_type, count(*) as units, coalesce(sum(qty_on_hand), 0) as cartons')->groupBy('unit_type')->get()->keyBy('unit_type');
            if ($weight === null) {
                $asnLine = DB::table('asn_lines')->where('id', $line->asn_line_id)->first(['weight_kg', 'received_cartons', 'expected_cartons']);
                $asnCartons = (int) ($asnLine?->received_cartons ?: $asnLine?->expected_cartons ?: 0);
                $weight = $asnLine?->weight_kg !== null && $asnCartons > 0 ? (float) $asnLine->weight_kg / $asnCartons : null;
            }
            if (isset($units['pallet'])) {
                $perPallet = max(1.0, (float) $units['pallet']->cartons / max(1, (int) $units['pallet']->units));

                return ['unit_type' => 'pallet', 'qty' => (int) ceil($cartons / $perPallet), 'weight_kg' => $weight, 'source' => 'stock_units'];
            }
            if (isset($units['carton'])) {
                return ['unit_type' => 'carton', 'qty' => $cartons, 'weight_kg' => $weight, 'source' => 'stock_units'];
            }
        }

        if (preg_match('/pallet|plt|托/iu', (string) $line->package_type) === 1) {
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
