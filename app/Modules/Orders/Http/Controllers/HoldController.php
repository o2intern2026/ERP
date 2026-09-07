<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderHoldService;
use App\Support\Enums;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** Holds on an order (ERP_PLAN §3.3): financial holds are Finance / admin only (OMS-11); the others are Coordinator tools. */
final class HoldController extends Controller
{
    public function store(Request $request, Order $order, OrderHoldService $holds): RedirectResponse
    {
        $data = $request->validate(['hold_type' => ['required', Rule::in(Enums::HOLD_TYPES)], 'reason' => ['required', 'string', 'max:255']]);
        $this->authorizeHold($data['hold_type']);

        $holds->place($order, $data['hold_type'], $data['reason'], $request->user()?->id);

        return back()->with('status', __('orders.holds.messages.placed'));
    }

    public function release(Request $request, Order $order, int $exception, OrderHoldService $holds): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:255']]);
        $hold = $holds->activeFor($order)->firstWhere('id', $exception);
        abort_if($hold === null, 404);
        $this->authorizeHold($hold->hold_type);

        try {
            $holds->release($order, $exception, $data['note'], $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['hold' => $e->getMessage()]);
        }

        return back()->with('status', __('orders.holds.messages.released'));
    }

    private function authorizeHold(string $holdType): void
    {
        $roles = $holdType === 'financial' ? OrderHoldService::FINANCE_ROLES : ['admin', 'customer_service', 'dispatcher', 'finance'];
        abort_unless(auth()->user()?->hasAnyRole($roles), 403, __('orders.holds.messages.finance_only'));
    }
}
