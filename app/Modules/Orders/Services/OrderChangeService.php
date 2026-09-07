<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Orders\Events\OrderCancelled;
use App\Modules\Orders\Events\OrderReduced;
use App\Modules\Orders\Exceptions\OrderRuleViolation;
use App\Modules\Orders\Models\Fulfilment;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Support\Facades\DB;

/**
 * A11 / OMS-4 change and cancel by stage (ERP_PLAN §3.4, lock point = picking):
 *  - received / confirmed / allocated: coordinators (admin, customer service, dispatcher) edit or cancel freely;
 *  - picking / packed: only a supervisor or admin, and a reason is required (it goes into the timeline);
 *  - dispatched / delivered: nothing can be edited or cancelled — only a return (§3.8 #4).
 * Cancellations emit `order.cancelled`, quantity reductions `order.reduced` (Warehouse releases the reservations).
 */
final class OrderChangeService
{
    public const COORDINATOR_ROLES = ['admin', 'customer_service', 'dispatcher'];

    public const SUPERVISOR_ROLES = ['admin', 'warehouse_supervisor'];

    public function __construct(
        private readonly OrderStatusService $statuses,
        private readonly FulfilmentService $fulfilments,
        private readonly OutboxPublisher $outbox,
    ) {}

    /** Throws when this user may not change the order at its current stage. */
    public function authorizeChange(Order $order, User $user): void
    {
        if ($order->isEditable()) {
            if (! $user->hasAnyRole(self::COORDINATOR_ROLES)) {
                throw new OrderRuleViolation(__('orders.changes.messages.forbidden'));
            }

            return;
        }
        if ($order->isEditableWithApproval()) {
            if (! $user->hasAnyRole(self::SUPERVISOR_ROLES)) {
                throw new OrderRuleViolation(__('orders.changes.messages.supervisor_required'));
            }

            return;
        }

        throw new OrderRuleViolation(__('orders.changes.messages.shipped_locked'));
    }

    /** Can this user change the order at all (used by the views to show the forms)? */
    public function canChange(Order $order, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        try {
            $this->authorizeChange($order, $user);

            return true;
        } catch (OrderRuleViolation) {
            return false;
        }
    }

    public function requiresReason(Order $order): bool
    {
        return $order->isEditableWithApproval();
    }

    public function cancel(Order $order, User $user, string $reason): Order
    {
        $this->authorizeChange($order, $user);

        return DB::transaction(function () use ($order, $user, $reason): Order {
            $locked = Order::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
            $previous = $locked->operational_status;
            $this->statuses->transitionOperational($locked, 'cancelled', $user->id, __('orders.changes.timeline.cancelled', ['reason' => $reason]));

            $this->outbox->publish(new OrderCancelled([
                'order_id' => $locked->id,
                'order_no' => $locked->order_no,
                'job_id' => $locked->job_id,
                'client_id' => $locked->client_id,
                'previous_status' => $previous,
                'cancelled_by' => $user->id,
                'reason' => $reason,
                'cancelled_at' => now()->toIso8601String(),
            ], $locked->job_id, $locked->client_id, $locked->job?->job_no ?? $locked->order_no));

            return $locked->refresh();
        });
    }

    /**
     * Reduce carton quantities (increases would need a new reservation — refused). Allocated batches shrink from the
     * newest one; `order.reduced` lists only lines tied to a goods line (asn_line_id), which is what Warehouse reserved.
     *
     * @param  array<int, int>  $newQuantities  order_line_id → new carton_qty (0 removes the line's goods, the line stays for audit)
     */
    public function reduce(Order $order, array $newQuantities, User $user, string $reason): Order
    {
        $this->authorizeChange($order, $user);

        return DB::transaction(function () use ($order, $newQuantities, $user, $reason): Order {
            $locked = Order::query()->withoutGlobalScopes()->lockForUpdate()->with('lines.fulfilmentLines.fulfilment')->findOrFail($order->id);
            $changes = [];

            foreach ($newQuantities as $lineId => $newQty) {
                /** @var OrderLine|null $line */
                $line = $locked->lines->firstWhere('id', (int) $lineId);
                $newQty = (int) $newQty;
                if ($line === null) {
                    throw new OrderRuleViolation(__('orders.changes.messages.unknown_line'));
                }
                if ($newQty === $line->carton_qty) {
                    continue;
                }
                if ($newQty > $line->carton_qty || $newQty < 0) {
                    throw new OrderRuleViolation(__('orders.changes.messages.increase_not_allowed'));
                }

                $changes[] = ['order_line_id' => $line->id, 'asn_line_id' => $line->asn_line_id, 'old_qty' => $line->carton_qty, 'new_qty' => $newQty];
                $line->update(['carton_qty' => $newQty]);
                $this->shrinkBatches($line, $newQty);
            }

            if ($changes === []) {
                throw new OrderRuleViolation(__('orders.changes.messages.nothing_changed'));
            }
            if ((int) $locked->lines()->sum('carton_qty') === 0) {
                throw new OrderRuleViolation(__('orders.changes.messages.use_cancel'));
            }

            $summary = collect($changes)->map(fn ($c) => "#{$c['order_line_id']} {$c['old_qty']} → {$c['new_qty']}")->implode('，');
            $this->statuses->note($locked, $user->id, __('orders.changes.timeline.reduced', ['summary' => $summary, 'reason' => $reason]));
            $this->fulfilments->resyncOperationalStatus($locked);

            $reserved = array_values(array_filter($changes, fn ($c) => $c['asn_line_id'] !== null));
            if ($reserved !== []) {
                $this->outbox->publish(new OrderReduced([
                    'order_id' => $locked->id,
                    'order_no' => $locked->order_no,
                    'job_id' => $locked->job_id,
                    'client_id' => $locked->client_id,
                    'lines' => $reserved,
                    'changed_by' => $user->id,
                    'reason' => $reason,
                    'changed_at' => now()->toIso8601String(),
                ], $locked->job_id, $locked->client_id, $locked->job?->job_no ?? $locked->order_no));
            }

            return $locked->refresh()->load('lines', 'fulfilments.lines');
        });
    }

    /**
     * Delivery details (name, phone, address, requested date, instructions) by stage; the reason is mandatory from picking on.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDelivery(Order $order, array $data, User $user, ?string $reason): Order
    {
        $this->authorizeChange($order, $user);
        if ($this->requiresReason($order) && blank($reason)) {
            throw new OrderRuleViolation(__('orders.changes.messages.reason_required'));
        }

        return DB::transaction(function () use ($order, $data, $user, $reason): Order {
            $order->update($data);
            $this->statuses->note($order, $user->id, __('orders.changes.timeline.delivery_updated', ['reason' => filled($reason) ? $reason : __('orders.not_provided')]));

            return $order->refresh();
        });
    }

    /** Take the excess off the newest batches first; an emptied, still-allocated batch disappears. */
    private function shrinkBatches(OrderLine $line, int $newQty): void
    {
        $excess = (int) $line->fulfilmentLines->sum('qty') - $newQty;
        foreach ($line->fulfilmentLines->sortByDesc('fulfilment_id') as $batchLine) {
            if ($excess <= 0) {
                break;
            }
            $cut = min($excess, (int) $batchLine->qty);
            $excess -= $cut;
            if ($cut === (int) $batchLine->qty) {
                $fulfilment = $batchLine->fulfilment;
                $batchLine->delete();
                if ($fulfilment !== null && $fulfilment->status === 'allocated' && $fulfilment->lines()->doesntExist()) {
                    Fulfilment::query()->whereKey($fulfilment->id)->delete();
                }
            } else {
                $batchLine->update(['qty' => $batchLine->qty - $cut]);
            }
        }
    }
}
