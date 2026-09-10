<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Exceptions\OrderRuleViolation;
use App\Modules\Orders\Http\OrderValidation;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderChangeService;
use App\Modules\Orders\Services\ReturnRequestService;
use App\Support\Auth\RequiredRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A11 / OMS-9: staff raise a return against a shipped order; Finance records the credit decision on the return order. */
final class ReturnController extends Controller
{
    public function store(Request $request, Order $order, ReturnRequestService $returns): RedirectResponse
    {
        RequiredRoles::requireAny(OrderChangeService::COORDINATOR_ROLES);
        // 2026-09-10 audit (portal twin): `quantities` optional so an empty selection is answered by the service's Chinese 请至少选择一行货物;
        // the rejected form comes back with the typed reason / quantities.
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['nullable', 'integer', 'min:0'],
        ], OrderValidation::messages(), OrderValidation::attributes());
        $lines = collect($data['quantities'] ?? [])->filter(fn ($qty) => (int) $qty > 0)
            ->map(fn ($qty, $lineId) => ['order_line_id' => (int) $lineId, 'qty' => (int) $qty])->values()->all();

        try {
            $return = $returns->request($order, $lines, $data['reason'], $request->user()->id, 'manual');
        } catch (OrderRuleViolation $e) {
            return back()->withErrors(['return' => $e->getMessage()])->withInput();
        }

        return redirect()->route('orders.show', $return)->with('status', __('orders.returns.messages.requested', ['order_no' => $return->order_no]));
    }

    public function decide(Request $request, Order $order, ReturnRequestService $returns): RedirectResponse
    {
        RequiredRoles::requireAny(ReturnRequestService::FINANCE_ROLES, __('orders.returns.messages.finance_only'));
        $data = $request->validate([
            'decision' => ['required', Rule::in(ReturnRequestService::DECISIONS)],
            'note' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $returns->recordFinancialDecision($order, $data['decision'], $data['note'], $request->user()->id);
        } catch (OrderRuleViolation $e) {
            return back()->withErrors(['return' => $e->getMessage()]);
        }

        return redirect()->route('orders.show', $order)->with('status', __('orders.returns.messages.decided'));
    }
}
