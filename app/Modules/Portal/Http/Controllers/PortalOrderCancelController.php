<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Exceptions\OrderRuleViolation;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderChangeService;
use App\Modules\Portal\Http\PortalValidation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Client-side cancellation (lead decision 2026-09-10, CR #111): stage 1 (received / confirmed / allocated) cancels directly,
 * stage 2 (picking / packed) files a 申请取消 for the coordinator, stage 3 (shipped) has only 申请退货 (PortalReturnController).
 */
class PortalOrderCancelController extends Controller
{
    public function cancel(Request $request, int $order, OrderChangeService $changes): RedirectResponse
    {
        abort_unless($request->user()?->isClientUser(), 403, __('portal.messages.client_only'));
        $order = Order::query()->findOrFail($order); // client-scoped query (see PortalOrderController::show)
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], PortalValidation::messages(), PortalValidation::attributes());

        try {
            $changes->cancelByClient($order, $request->user(), $data['reason']);
        } catch (OrderRuleViolation $e) {
            return back()->withErrors(['cancel' => $e->getMessage()])->withInput();
        }

        return redirect()->route('portal.orders.show', $order)->with('status', __('portal.cancel.done', ['order_no' => $order->order_no]));
    }

    public function request(Request $request, int $order, OrderChangeService $changes): RedirectResponse
    {
        abort_unless($request->user()?->isClientUser(), 403, __('portal.messages.client_only'));
        $order = Order::query()->findOrFail($order);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], PortalValidation::messages(), PortalValidation::attributes());

        try {
            $changes->requestCancel($order, $request->user(), $data['reason']);
        } catch (OrderRuleViolation $e) {
            return back()->withErrors(['cancel' => $e->getMessage()])->withInput();
        }

        return redirect()->route('portal.orders.show', $order)->with('status', __('portal.cancel.requested'));
    }
}
