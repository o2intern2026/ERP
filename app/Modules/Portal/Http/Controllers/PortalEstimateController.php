<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Exceptions\OrderRuleViolation;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderEstimateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A7b in the portal: a client prices its own order (客户价 only — the estimate view model never carries cost). */
final class PortalEstimateController extends Controller
{
    public function __invoke(Request $request, int $order, OrderEstimateService $estimates): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->isClientUser() && $user->client_id !== null, 403, __('portal.messages.client_only'));
        // Resolved inside the action so the client scope (set by middleware) filters it: another client's order is a 404.
        $order = Order::query()->findOrFail($order);

        try {
            $estimates->estimate($order, $user->id);
        } catch (OrderRuleViolation $e) {
            return back()->withErrors(['estimate' => $e->getMessage()]);
        }

        return redirect()->route('portal.orders.show', $order)->with('status', __('portal.estimate.created'));
    }
}
