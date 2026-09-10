<?php

namespace App\Support\Ui;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\HtmlString;

/**
 * One way to show a status everywhere (tester feedback 2026-09-10: list columns showed plain text, no colour):
 * `{!! StatusBadge::render('orders.statuses.operational.', $order->operational_status) !!}` → <span class="badge" data-tone="…">已发运</span>.
 * The tone comes from the per-status-set map below, else from the generic word list; unknown → warn (something in progress).
 */
final class StatusBadge
{
    /** @var array<string, array<string, string>> lang prefix → status → tone (ok | warn | info | danger | muted) */
    private const TONES = [
        'orders.statuses.operational.' => ['received' => 'info', 'confirmed' => 'warn', 'allocated' => 'warn', 'picking' => 'warn', 'packed' => 'warn', 'dispatched' => 'info', 'delivered' => 'ok', 'returned' => 'muted', 'cancelled' => 'danger'],
        'orders.customer_statuses.' => ['received' => 'info', 'confirmed' => 'warn', 'in_warehouse' => 'warn', 'out_for_delivery' => 'info', 'delivered' => 'ok', 'returned' => 'muted', 'cancelled' => 'danger', 'invoiced' => 'ok'],
        'orders.statuses.fulfilment.' => ['unfulfilled' => 'muted', 'partial' => 'warn', 'fulfilled' => 'ok'],
        'orders.statuses.billing.' => ['unbilled' => 'muted', 'partially_billed' => 'warn', 'billed' => 'ok', 'credited' => 'info'],
        'orders.fulfilment_batch_statuses.' => ['allocated' => 'warn', 'picking' => 'warn', 'packed' => 'info', 'dispatched' => 'ok', 'delivered' => 'ok', 'cancelled' => 'danger'],
        'transport.statuses.' => ['quoting' => 'warn', 'quoted' => 'warn', 'quote_confirmed' => 'info', 'booked' => 'info', 'dispatched' => 'info', 'in_transit' => 'info', 'delivered' => 'ok', 'failed' => 'danger', 'booking_cancelled' => 'danger', 'return_requested' => 'warn', 'return_in_transit' => 'info', 'arrived_warehouse' => 'ok'],
        'transport.run_statuses.' => ['planned' => 'warn', 'in_progress' => 'info', 'completed' => 'ok', 'cancelled' => 'danger'],
        'transport.costs.statuses.' => ['missing' => 'danger', 'estimated' => 'warn', 'expected' => 'warn', 'confirmed' => 'ok', 'reconciled' => 'ok'],
        'transport.reconciliation.statuses.' => ['open' => 'warn', 'matched' => 'ok', 'disputed' => 'danger', 'closed' => 'muted'],
        'warehouse.asn_statuses.' => ['booked' => 'muted', 'arrived' => 'info', 'receiving' => 'warn', 'putaway' => 'ok', 'closed' => 'muted'],
        'warehouse.task_statuses.' => ['pending' => 'warn', 'in_progress' => 'info', 'done' => 'ok', 'cancelled' => 'muted', 'exception' => 'danger'],
        'warehouse.reservation_statuses.' => ['active' => 'warn', 'released' => 'muted', 'consumed' => 'ok'],
        'warehouse.receipt_statuses.' => ['open' => 'warn', 'completed' => 'ok'],
        'warehouse.wave_statuses.' => ['released' => 'warn', 'picking' => 'warn', 'completed' => 'ok', 'cancelled' => 'danger'],
        'billing.invoices.statuses.' => ['draft' => 'muted', 'issued' => 'info', 'part_paid' => 'warn', 'paid' => 'ok', 'void' => 'danger'],
        'platform.exceptions.statuses.' => ['open' => 'danger', 'in_progress' => 'warn', 'resolved' => 'ok'],
        'masterdata.statuses.' => ['active' => 'ok', 'inactive' => 'muted', 'pending' => 'warn'],
    ];

    private const GENERIC = [
        'ok' => ['done', 'completed', 'complete', 'delivered', 'paid', 'active', 'approved', 'matched', 'imported', 'fulfilled', 'billed', 'putaway', 'issued', 'ready', 'accepted', 'available', 'restocked', 'inspected', 'received_ok', 'settled', 'confirmed_cost'],
        'danger' => ['failed', 'cancelled', 'void', 'rejected', 'dead', 'exception', 'disputed', 'missing', 'damaged', 'quarantine', 'short', 'error', 'blocked', 'overdue'],
        'muted' => ['draft', 'closed', 'superseded', 'inactive', 'released', 'unbilled', 'unfulfilled', 'returned', 'booked', 'skipped', 'expired', 'not_provided', 'none'],
        'info' => ['in_progress', 'in_transit', 'dispatched', 'booked_in', 'sent', 'counting', 'received'],
    ];

    public static function render(string $prefix, ?string $status, ?string $label = null): HtmlString
    {
        if ($status === null || $status === '') {
            return new HtmlString('<span class="text-muted">—</span>');
        }
        $label ??= Lang::has($prefix.$status) ? __($prefix.$status) : $status;

        return new HtmlString('<span class="badge" data-tone="'.self::tone($prefix, $status).'">'.e($label).'</span>');
    }

    public static function tone(string $prefix, string $status): string
    {
        if (isset(self::TONES[$prefix][$status])) {
            return self::TONES[$prefix][$status];
        }
        foreach (self::GENERIC as $tone => $words) {
            if (in_array($status, $words, true)) {
                return $tone;
            }
        }

        return 'warn';
    }
}
