<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Exceptions\OrderRuleViolation;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderEstimateService;
use App\Support\Auth\RequiredRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A7b: staff price an order's expected service fees (+ Transport's preliminary freight) into a customer quote. */
final class OrderEstimateController extends Controller
{
    public function store(Request $request, Order $order, OrderEstimateService $estimates): RedirectResponse
    {
        RequiredRoles::requireAny(OrderEstimateService::STAFF_ROLES);

        try {
            $quote = $estimates->estimate($order, $request->user()->id);
        } catch (OrderRuleViolation $e) {
            return back()->withErrors(['estimate' => $e->getMessage()]);
        }

        return redirect()->route('orders.show', $order)->with('status', __('orders.estimate.messages.created', ['quote_no' => $quote->quote_no]));
    }
}
