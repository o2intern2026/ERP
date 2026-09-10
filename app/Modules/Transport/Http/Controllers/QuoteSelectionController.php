<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\QuoteSelectionService;
use App\Support\Auth\RequiredRoles;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QuoteSelectionController extends Controller
{
    public function __invoke(
        Request $request,
        Shipment $shipment,
        TransportQuote $quote,
        QuoteSelectionService $selection,
    ): RedirectResponse {
        $user = $request->user();
        RequiredRoles::requireAny(['admin', 'customer_service', 'dispatcher', 'transport_operator']);

        try {
            $selection->select($shipment, $quote, 'coordinator', $user->id);
        } catch (DomainException $exception) {
            return back()->withErrors(['quote' => $exception->getMessage()]);
        }

        // 2026-09-10 audit: the layout flash renders session('status') only — 'success' was never shown.
        return back()->with('status', __('transport.selection.saved'));
    }
}
