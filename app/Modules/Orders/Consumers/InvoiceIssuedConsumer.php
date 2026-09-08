<?php

namespace App\Modules\Orders\Consumers;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderStatusService;
use App\Support\Outbox\EventConsumer;
use InvalidArgumentException;

/**
 * contracts/events.md `invoice.issued` (CHANGE_REQUESTS #65): Billing issued an invoice; every order it lists in `order_ids`
 * moves to `billing_status = billed` (§3.8 #3 — the billing line of the order is driven by Billing, never by Orders guessing).
 * `job_ids` whose orders are not in `order_ids` are left alone: Billing lists only the orders it could attribute a line to.
 * Replay-safe: an order already `billed` / `credited` is skipped, so a redelivered event changes nothing.
 */
final class InvoiceIssuedConsumer implements EventConsumer
{
    /** Billing statuses that an invoice can no longer advance (enums.md §3: unbilled → partially_billed → billed → credited). */
    private const SETTLED = ['billed', 'credited'];

    public function __construct(private readonly OrderStatusService $statuses) {}

    public function handle(array $envelope): void
    {
        $payload = $envelope['payload'];
        $orderIds = collect($payload['order_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($orderIds->isEmpty()) {
            return; // storage / manual invoices without an order line: nothing to move
        }

        $note = __('orders.billing.timeline.invoiced', [
            'invoice_no' => (string) ($payload['invoice_no'] ?? ''),
            'type' => __('orders.billing.invoice_types.'.($payload['invoice_type'] ?? 'service')),
        ]);

        foreach ($orderIds as $orderId) {
            $order = Order::query()->withoutGlobalScopes()->find($orderId);
            if ($order === null || (int) $order->client_id !== (int) ($payload['client_id'] ?? 0)) {
                // A wrong id is a Billing / contract fault, not something to paper over: fail loudly so the event is retried and dead-lettered.
                throw new InvalidArgumentException("invoice.issued {$payload['invoice_no']} lists order {$orderId}, which does not belong to client {$payload['client_id']}.");
            }
            if (in_array($order->billing_status, self::SETTLED, true)) {
                continue;
            }
            $this->statuses->transitionBilling($order, 'billed', null, $note);
        }
    }
}
