<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Platform\Models\ExceptionRecord;
use Illuminate\Support\Collection;

/**
 * 客户请求 inbox (CR #112, 2026-09-10): everything a client asked for from the portal that staff must act on —
 * stage-2 取消申请 (cancel_request exceptions) and 退货申请 (return orders created from the portal), plus a 30-day history.
 * Read-only aggregation; the actions stay where they are (OrderChangeController::cancel / rejectCancelRequest, the return order page).
 */
final class ClientRequestService
{
    /** Return orders the client filed that nothing has happened to yet. */
    public const RETURN_PENDING_STATUSES = ['received', 'confirmed'];

    public const STAFF_ROLES = ['admin', 'customer_service', 'dispatcher', 'warehouse_supervisor', 'finance'];

    /** @return Collection<int, ExceptionRecord> open cancel requests with their order + requester */
    public function pendingCancelRequests(): Collection
    {
        return ExceptionRecord::query()->withoutGlobalScopes()->where('type', 'cancel_request')->whereIn('status', ['open', 'in_progress'])
            ->with(['order' => fn ($q) => $q->withoutGlobalScopes()->with('client'), 'creator'])->orderBy('created_at')->get();
    }

    /** @return Collection<int, Order> portal return requests not yet collected */
    public function pendingReturnRequests(): Collection
    {
        return Order::query()->withoutGlobalScopes()->where('order_type', 'return')->where('source', 'portal')
            ->whereIn('operational_status', self::RETURN_PENDING_STATUSES)
            ->with(['client', 'originalOrder' => fn ($q) => $q->withoutGlobalScopes(), 'lines'])->orderBy('created_at')->get()
            ->each(fn (Order $return) => $return->setAttribute('request_note', $return->events()->where('dimension', 'operational')->where('to_status', 'confirmed')->latest('id')->value('note')));
    }

    public function pendingCount(): int
    {
        return ExceptionRecord::query()->withoutGlobalScopes()->where('type', 'cancel_request')->whereIn('status', ['open', 'in_progress'])->count()
            + Order::query()->withoutGlobalScopes()->where('order_type', 'return')->where('source', 'portal')->whereIn('operational_status', self::RETURN_PENDING_STATUSES)->count();
    }

    /** @return array{cancels: Collection<int, ExceptionRecord>, returns: Collection<int, Order>} decided / progressed within the window */
    public function history(int $days = 30): array
    {
        $since = now()->subDays($days);

        return [
            'cancels' => ExceptionRecord::query()->withoutGlobalScopes()->where('type', 'cancel_request')->where('status', 'resolved')->where('resolved_at', '>=', $since)
                ->with(['order' => fn ($q) => $q->withoutGlobalScopes()->with('client'), 'resolver'])->orderByDesc('resolved_at')->limit(100)->get(),
            'returns' => Order::query()->withoutGlobalScopes()->where('order_type', 'return')->where('source', 'portal')
                ->whereNotIn('operational_status', self::RETURN_PENDING_STATUSES)->where('updated_at', '>=', $since)
                ->with(['client', 'originalOrder' => fn ($q) => $q->withoutGlobalScopes()])->orderByDesc('updated_at')->limit(100)->get(),
        ];
    }
}
