<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderHoldService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** A15 / OMS-2: the Coordinator queue — preset views over the order list (to confirm, short, financial hold, exceptions, due today). */
final class QueueController extends Controller
{
    public const VIEWS = ['pending_confirm', 'short', 'financial_hold', 'exceptions', 'today'];

    public function index(Request $request, OrderHoldService $holds): View
    {
        abort_unless(auth()->user()?->hasAnyRole(['admin', 'customer_service', 'dispatcher', 'finance']), 403);
        $view = $request->validate(['view' => ['nullable', Rule::in(self::VIEWS)]])['view'] ?? 'pending_confirm';

        $counts = collect(self::VIEWS)->mapWithKeys(fn ($v) => [$v => $this->query($v)->count()]);
        $orders = $this->query($view)->with(['client', 'job'])->orderBy('requested_date')->orderBy('id')->paginate(50)->withQueryString();

        return view('orders::queue.index', [
            'view' => $view, 'views' => self::VIEWS, 'counts' => $counts, 'orders' => $orders,
            'holdTypes' => $holds->activeTypesFor($orders->getCollection()),
        ]);
    }

    private function query(string $view)
    {
        $open = ['confirmed', 'allocated', 'picking', 'packed'];

        return match ($view) {
            'pending_confirm' => Order::query()->where('operational_status', 'received'),
            'short' => Order::query()->whereIn('operational_status', $open)->where(fn ($q) => $q->where('fulfilment_status', 'partial')->orWhereHas('lines', fn ($l) => $l->where('qty_backordered', '>', 0))),
            'financial_hold' => Order::query()->whereNotIn('operational_status', ['delivered', 'returned', 'cancelled'])->where(function ($q) {
                $active = DB::table('exceptions')->where('type', 'hold')->where('hold_type', 'financial')->where('status', '!=', 'resolved');
                $q->whereIn('id', (clone $active)->whereNotNull('order_id')->select('order_id'))
                    ->orWhereIn('client_id', (clone $active)->whereNull('order_id')->select('client_id'));
            }),
            'exceptions' => Order::query()->whereIn('id', DB::table('exceptions')->where('status', '!=', 'resolved')->where('type', '!=', 'hold')->whereNotNull('order_id')->select('order_id')),
            default => Order::query()->whereDate('requested_date', today())->whereIn('operational_status', $open),
        };
    }
}
