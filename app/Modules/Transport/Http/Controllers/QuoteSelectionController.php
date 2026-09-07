<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\QuoteSelectionService;
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
        abort_unless($user !== null && $user->hasAnyRole(['admin', 'customer_service', 'dispatcher', 'transport_operator']), 403);

        try {
            $selection->select($shipment, $quote, 'coordinator', $user->id);
        } catch (DomainException $exception) {
            return back()->withErrors(['quote' => $exception->getMessage()]);
        }

        return back()->with('success', __('transport.selection.saved'));
    }
}
