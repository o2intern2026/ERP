<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Events\ReturnFinancialDecision;
use App\Modules\Orders\Events\ReturnRequested;
use App\Modules\Orders\Exceptions\OrderRuleViolation;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Support\Facades\DB;

/**
 * A11 / OMS-9 return chain (contracts/enums.md §7): a return request is an order with order_type = return linked to the
 * original (CHANGE_REQUESTS #10) → `return.requested` (Transport collects, Warehouse expects the receipt) →
 * `return.inspected` closes the return order as `returned` → Finance records credit / no_credit on the return order →
 * `return.financial_decision` (the only credit trigger, CHANGE_REQUESTS #11). Stock never moves here.
 */
final class ReturnRequestService
{
    public const DECISIONS = ['credit', 'no_credit'];

    public const FINANCE_ROLES = ['admin', 'finance'];

    public function __construct(
        private readonly OrderCreationService $orders,
        private readonly OrderStatusService $statuses,
        private readonly OutboxPublisher $outbox,
    ) {}

    /**
     * Raise a return against a shipped order. Lines default to everything shipped.
     *
     * @param  list<array{order_line_id:int, qty:int}>  $lines
     * @param  array{name:?string, phone:?string, address:string, suburb:string, state:string, postcode:string}|null  $pickupAddress  where the goods are collected (default: the original deliver-to)
     */
    public function request(Order $original, array $lines, string $reason, ?int $actorId, string $source = 'manual', ?array $pickupAddress = null): Order
    {
        if (! $original->acceptsReturnRequest()) {
            throw new OrderRuleViolation(__('orders.returns.messages.not_shipped'));
        }

        $original->loadMissing('lines', 'fulfilments');
        $originalLines = $original->lines->keyBy('id');
        $returnLines = [];
        foreach ($lines as $requested) {
            /** @var OrderLine|null $line */
            $line = $originalLines->get((int) ($requested['order_line_id'] ?? 0));
            $qty = (int) ($requested['qty'] ?? 0);
            $shipped = $line?->qty_shipped ?: $line?->carton_qty;
            if ($line === null || $qty < 1 || $qty > $shipped) {
                throw new OrderRuleViolation(__('orders.returns.messages.bad_quantity'));
            }
            $returnLines[] = [
                'description_cn' => $line->description_cn, 'description_en' => $line->description_en, 'package_type' => $line->package_type,
                'carton_qty' => $qty, 'actual_weight_kg' => $line->actual_weight_kg, 'length_mm' => $line->length_mm, 'width_mm' => $line->width_mm,
                'height_mm' => $line->height_mm, 'cbm' => $line->cbm, 'asn_line_id' => $line->asn_line_id, 'original_order_line_id' => $line->id,
            ];
        }
        if ($returnLines === []) {
            throw new OrderRuleViolation(__('orders.returns.messages.no_lines'));
        }

        $pickup = $pickupAddress ?? [
            'name' => $original->deliver_to_name, 'phone' => $original->deliver_to_phone, 'address' => $original->deliver_to_address,
            'suburb' => $original->deliver_to_suburb, 'state' => $original->deliver_to_state, 'postcode' => $original->deliver_to_postcode,
        ];

        return DB::transaction(function () use ($original, $returnLines, $reason, $actorId, $source, $pickup): Order {
            // The return travels on the original Job (same commission; Billing credits against its charges). deliver_to stays the
            // customer's address on record; pickup_address is where Transport collects the goods.
            $return = $this->orders->create([
                'client_id' => $original->client_id, 'job_id' => $original->job_id, 'order_type' => 'return',
                'consignment_mark' => $original->consignment_mark, 'fba_reference' => $original->fba_reference, 'pickup_address' => $pickup,
                'deliver_to_name' => $original->deliver_to_name, 'deliver_to_phone' => $original->deliver_to_phone, 'deliver_to_address' => $original->deliver_to_address,
                'deliver_to_suburb' => $original->deliver_to_suburb, 'deliver_to_state' => $original->deliver_to_state, 'deliver_to_postcode' => $original->deliver_to_postcode,
                'deliver_to_address_type' => $original->deliver_to_address_type, 'requested_date' => today()->toDateString(), 'service_level' => 'standard',
                'original_order_id' => $original->id, 'lines' => $returnLines,
            ], $actorId, $source);

            $return = $this->statuses->transitionOperational($return, 'confirmed', $actorId, __('orders.returns.timeline.requested', ['order_no' => $original->order_no, 'reason' => $reason]));
            $this->statuses->note($original, $actorId, __('orders.returns.timeline.raised', ['return_no' => $return->order_no, 'reason' => $reason]));

            $this->outbox->publish(new ReturnRequested([
                'return_order_id' => $return->id,
                'return_order_no' => $return->order_no,
                'original_order_id' => $original->id,
                'original_shipment_id' => $original->fulfilments->whereNotNull('shipment_id')->first()?->shipment_id,
                'job_id' => $return->job_id,
                'client_id' => $return->client_id,
                'reason' => $reason,
                'lines' => $return->lines->map(fn (OrderLine $line) => [
                    'original_order_line_id' => $line->original_order_line_id,
                    'asn_line_id' => $line->asn_line_id,
                    'qty' => $line->carton_qty,
                ])->values()->all(),
                'pickup_address' => $pickup,
                'requested_by' => $actorId,
                'requested_at' => now()->toIso8601String(),
            ], $return->job_id, $return->client_id, $original->job?->job_no ?? $original->order_no));

            return $return->refresh()->load('lines', 'events');
        });
    }

    /**
     * `return.inspected` (Warehouse): the return order closes as `returned`; the original order records the outcome.
     * Replay-tolerant — an already returned order is left alone.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyInspection(array $payload): void
    {
        DB::transaction(function () use ($payload): void {
            $original = ! empty($payload['original_order_id'])
                ? Order::query()->withoutGlobalScopes()->lockForUpdate()->find((int) $payload['original_order_id'])
                : null;
            $return = ! empty($payload['return_order_id'])
                ? Order::query()->withoutGlobalScopes()->lockForUpdate()->find((int) $payload['return_order_id'])
                : $this->singleOpenReturnFor($original);

            $summary = collect($payload['lines'] ?? [])
                ->groupBy('disposition')
                ->map(fn ($lines, $disposition) => __('orders.returns.dispositions.'.$disposition).' × '.(int) $lines->sum('received_qty'))
                ->implode('，');
            $note = __('orders.returns.timeline.inspected', ['receipt' => $payload['return_receipt_id'] ?? '—', 'summary' => $summary ?: '—']);

            if ($return !== null && $return->order_type === 'return') {
                if ($return->return_inspected_at === null) {
                    $return->update(['return_inspected_at' => $payload['inspected_at'] ?? now()]);
                }
                if ($return->operational_status !== 'returned' && ! in_array($return->operational_status, OrderStatusService::TERMINAL, true)) {
                    $this->statuses->transitionOperational($return, 'returned', null, $note);
                } elseif ($return->operational_status === 'returned') {
                    // replay or a second receipt for the same return: keep the fact, do not move anything
                    $this->statuses->note($return, null, $note);
                }
            }

            if ($original !== null && $original->id !== $return?->id) {
                $this->statuses->note($original, null, $note);
            }
        });
    }

    /**
     * Finance's decision on an inspected return — the only thing that may trigger a credit note (Billing consumes the event).
     *
     * @param  list<array{original_order_line_id:int, qty:int, amount_cents:?int, reason:?string}>  $creditLines  defaults to every return line, amount left to Billing
     */
    public function recordFinancialDecision(Order $return, string $decision, string $note, int $actorId, array $creditLines = []): Order
    {
        if ($return->order_type !== 'return') {
            throw new OrderRuleViolation(__('orders.returns.messages.not_a_return'));
        }
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new OrderRuleViolation(__('orders.returns.messages.bad_decision'));
        }

        return DB::transaction(function () use ($return, $decision, $note, $actorId, $creditLines): Order {
            $locked = Order::query()->withoutGlobalScopes()->lockForUpdate()->with('lines')->findOrFail($return->id);
            if ($locked->return_inspected_at === null) {
                throw new OrderRuleViolation(__('orders.returns.messages.not_inspected'));
            }
            if ($locked->return_decision !== null) {
                throw new OrderRuleViolation(__('orders.returns.messages.already_decided'));
            }

            $decidedAt = now();
            $locked->update(['return_decision' => $decision, 'return_decided_by' => $actorId, 'return_decided_at' => $decidedAt, 'return_decision_note' => $note]);
            $this->statuses->note($locked, $actorId, __('orders.returns.timeline.decided', ['decision' => __('orders.returns.decisions.'.$decision), 'note' => $note]));

            $lines = $decision === 'credit'
                ? ($creditLines !== [] ? $creditLines : $locked->lines->map(fn (OrderLine $line) => ['original_order_line_id' => $line->original_order_line_id, 'qty' => $line->carton_qty, 'amount_cents' => null, 'reason' => $note])->all())
                : [];

            $this->outbox->publish(new ReturnFinancialDecision([
                'return_order_id' => $locked->id,
                'original_order_id' => $locked->original_order_id,
                'job_id' => $locked->job_id,
                'client_id' => $locked->client_id,
                'decision' => $decision,
                'credit_lines' => array_map(fn (array $line) => [
                    'original_charge_id' => $line['original_charge_id'] ?? null,
                    'original_order_line_id' => (int) $line['original_order_line_id'],
                    'qty' => (int) $line['qty'],
                    'amount_cents' => isset($line['amount_cents']) ? (int) $line['amount_cents'] : null,
                    'reason' => $line['reason'] ?? $note,
                ], array_values($lines)),
                'decided_by' => $actorId,
                'decided_at' => $decidedAt->toIso8601String(),
                'note' => $note,
            ], $locked->job_id, $locked->client_id, $locked->job?->job_no ?? $locked->order_no));

            return $locked->refresh();
        });
    }

    /** When Warehouse opened the receipt without the return order id, an unambiguous open return on the original still closes. */
    private function singleOpenReturnFor(?Order $original): ?Order
    {
        if ($original === null) {
            return null;
        }
        $open = Order::query()->withoutGlobalScopes()->lockForUpdate()
            ->where('original_order_id', $original->id)->where('order_type', 'return')
            ->whereNotIn('operational_status', OrderStatusService::TERMINAL)
            ->get();

        return $open->count() === 1 ? $open->first() : null;
    }
}
