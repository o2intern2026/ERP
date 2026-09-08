<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\QuoteSelectionService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * §5.7 #2 / §7 step 4: the client confirms the recommended final quote or picks another one. The selection itself is
 * Transport's `QuoteSelectionService::select(..., 'client', userId)`, which validates the actor against the shipment's
 * client, marks the quote selected, moves the shipment to `quote_confirmed` and emits `shipment.quote_confirmed`
 * (Billing bills the freight from that event — never from here).
 */
final class PortalQuoteController extends Controller
{
    public function confirm(Request $request, int $order, int $quote, QuoteSelectionService $selection): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->isClientUser() && $user->client_id !== null, 403, __('portal.messages.client_only'));
        // Resolved inside the action so the client scope (set by middleware) filters it: another client's order is a 404.
        $order = Order::query()->findOrFail($order);

        // The quote must belong to one of this order's outbound shipments; Shipment is client-scoped (and the client id is
        // repeated explicitly), so a quote of another client's shipment is a 404 — never a 403 that reveals it exists.
        $quote = TransportQuote::query()->whereKey($quote)->where('quote_stage', 'final')
            ->whereHas('shipment', fn ($q) => $q->where('order_id', $order->id)->where('shipment_type', 'outbound')->where('client_id', $user->client_id))
            ->firstOrFail();
        $shipment = Shipment::query()->where('order_id', $order->id)->findOrFail($quote->shipment_id);

        try {
            $selection->select($shipment, $quote, 'client', $user->id);
        } catch (DomainException $e) {
            return back()->withErrors(['quote' => $e->getMessage()]);
        }

        return redirect()->route('portal.orders.show', $order)->with('status', __('portal.quotes.confirmed', ['shipment_no' => $shipment->shipment_no]));
    }
}
