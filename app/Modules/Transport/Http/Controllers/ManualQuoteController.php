<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Http\TransportValidation;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\ManualQuoteService;
use App\Support\Auth\RequiredRoles;
use App\Support\Money;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManualQuoteController extends Controller
{
    public function __invoke(Request $request, Shipment $shipment, ManualQuoteService $quotes): RedirectResponse
    {
        RequiredRoles::requireAny(['admin', 'customer_service', 'dispatcher']); // CHANGE_REQUESTS #130: the dispatcher plans, the driver executes
        // CHANGE_REQUESTS #135 (audit TMS-03 / CS-19): the form takes DOLLARS with two decimals like every Billing form; the service,
        // the quote row and the events keep integer cents.
        $data = $request->validate([
            'carrier_service_id' => ['required', 'integer', 'exists:carrier_services,id'],
            'quote_stage' => ['required', Rule::in(['preliminary', 'final'])],
            'cost' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'customer_price' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'eta_days' => ['required', 'integer', 'min:0', 'max:365'],
        ], TransportValidation::messages(), TransportValidation::attributes());
        $cost = Money::fromDecimal($data['cost']);
        $price = Money::fromDecimal($data['customer_price']);

        try {
            $quotes->record(
                $shipment,
                CarrierService::query()->findOrFail((int) $data['carrier_service_id']),
                $data['quote_stage'],
                $cost->cents,
                $price->cents,
                (int) $data['eta_days'],
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['manual_quote' => $exception->getMessage()]);
        }

        return back()->with('status', __('transport.manual_quote.saved_amounts', ['cost' => $cost->format(), 'price' => $price->format()]));
    }
}
