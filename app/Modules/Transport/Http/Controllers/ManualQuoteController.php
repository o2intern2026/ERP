<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\ManualQuoteService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManualQuoteController extends Controller
{
    public function __invoke(Request $request, Shipment $shipment, ManualQuoteService $quotes): RedirectResponse
    {
        abort_unless($request->user()?->hasAnyRole(['admin', 'customer_service', 'dispatcher', 'transport_operator']), 403);
        $data = $request->validate([
            'carrier_service_id' => ['required', 'integer', 'exists:carrier_services,id'],
            'quote_stage' => ['required', Rule::in(['preliminary', 'final'])],
            'cost_cents' => ['required', 'integer', 'min:1'],
            'customer_price_cents' => ['required', 'integer', 'min:1'],
            'eta_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        try {
            $quotes->record(
                $shipment,
                CarrierService::query()->findOrFail((int) $data['carrier_service_id']),
                $data['quote_stage'],
                (int) $data['cost_cents'],
                (int) $data['customer_price_cents'],
                (int) $data['eta_days'],
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['manual_quote' => $exception->getMessage()]);
        }

        return back()->with('status', __('transport.manual_quote.saved'));
    }
}
