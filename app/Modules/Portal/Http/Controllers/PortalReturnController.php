<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Exceptions\OrderRuleViolation;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\ReturnRequestService;
use App\Modules\Portal\Http\PortalValidation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A9-p: a client raises a return on its own shipped order; the same OMS-9 chain as the staff page (source = portal). */
final class PortalReturnController extends Controller
{
    public function store(Request $request, int $order, ReturnRequestService $returns): RedirectResponse
    {
        abort_unless($request->user()?->isClientUser(), 403, __('portal.messages.client_only'));
        $order = Order::query()->findOrFail($order); // client-scoped query (see PortalOrderController::show)
        // 2026-09-10 audit: `quantities` is optional here — a post without a positive quantity (all zeros, or a line-less pure
        // transport order) is answered by ReturnRequestService's Chinese 请至少选择一行货物, not an English "quantities field is required".
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['nullable', 'integer', 'min:0'],
        ], PortalValidation::messages(), PortalValidation::attributes());
        $lines = collect($data['quantities'] ?? [])->filter(fn ($qty) => (int) $qty > 0)
            ->map(fn ($qty, $lineId) => ['order_line_id' => (int) $lineId, 'qty' => (int) $qty])->values()->all();

        try {
            $return = $returns->request($order, $lines, $data['reason'], $request->user()->id, 'portal');
        } catch (OrderRuleViolation $e) {
            return back()->withErrors(['return' => $e->getMessage()])->withInput(); // the panel reopens with the typed reason / quantities
        }

        return redirect()->route('portal.orders.show', $return)->with('status', __('portal.messages.return_requested', ['order_no' => $return->order_no]));
    }
}
