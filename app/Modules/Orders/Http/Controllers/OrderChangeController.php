<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Exceptions\OrderRuleViolation;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A11 / OMS-4: cancel and quantity changes by stage; the service decides who may do what, the reason lands in the timeline. */
final class OrderChangeController extends Controller
{
    public function cancel(Request $request, Order $order, OrderChangeService $changes): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $changes->cancel($order, $request->user(), $data['reason']);
        } catch (OrderRuleViolation $e) {
            return back()->withErrors(['change' => $e->getMessage()]);
        }

        return redirect()->route('orders.show', $order)->with('status', __('orders.changes.messages.cancelled'));
    }

    public function reduce(Request $request, Order $order, OrderChangeService $changes): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'quantities' => ['required', 'array'],
            'quantities.*' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $changes->reduce($order, array_map('intval', $data['quantities']), $request->user(), $data['reason']);
        } catch (OrderRuleViolation $e) {
            return back()->withErrors(['change' => $e->getMessage()]);
        }

        return redirect()->route('orders.show', $order)->with('status', __('orders.changes.messages.reduced'));
    }
}
