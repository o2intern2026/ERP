<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderHoldService;
use App\Support\Auth\RequiredRoles;
use App\Support\Enums;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** Holds on an order (ERP_PLAN §3.3, A13): financial holds are placed by Finance / admin and released by Finance / admin / dispatcher (OMS-11). */
final class HoldController extends Controller
{
    public function store(Request $request, Order $order, OrderHoldService $holds): RedirectResponse
    {
        $data = $request->validate(['hold_type' => ['required', Rule::in(Enums::HOLD_TYPES)], 'reason' => ['required', 'string', 'max:255']]);
        $this->authorizeHold($data['hold_type'], false);

        $holds->place($order, $data['hold_type'], $data['reason'], $request->user()?->id);

        return back()->with('status', __('orders.holds.messages.placed'));
    }

    public function release(Request $request, Order $order, int $exception, OrderHoldService $holds): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:255']]);
        $hold = $holds->activeFor($order)->firstWhere('id', $exception);
        abort_if($hold === null, 404);
        $this->authorizeHold($hold->hold_type, true);

        try {
            $holds->release($order, $exception, $data['note'], $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['hold' => $e->getMessage()]);
        }

        return back()->with('status', __('orders.holds.messages.released'));
    }

    /** A13: Finance / admin place a financial hold, Finance / admin / dispatcher release it; other holds are Coordinator tools. */
    private function authorizeHold(string $holdType, bool $release): void
    {
        $roles = OrderHoldService::rolesFor($holdType, $release);
        RequiredRoles::requireAny($roles, __($release ? 'orders.holds.messages.release_roles' : 'orders.holds.messages.finance_only'));
    }
}
