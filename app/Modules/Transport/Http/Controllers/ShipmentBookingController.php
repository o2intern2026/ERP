<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Http\TransportValidation;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\ShipmentBookingService;
use App\Support\Auth\RequiredRoles;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShipmentBookingController extends Controller
{
    public function __invoke(Request $request, Shipment $shipment, ShipmentBookingService $bookings): RedirectResponse
    {
        RequiredRoles::requireAny(['admin', 'customer_service', 'dispatcher', 'transport_operator']);
        $data = $request->validate([
            'booking_reference' => ['nullable', 'string', 'max:255'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'pickup_date' => ['nullable', 'date_format:Y-m-d'],
        ], TransportValidation::messages(), TransportValidation::attributes());

        try {
            $bookings->book(
                $shipment,
                $data['booking_reference'] ?? null,
                $data['tracking_number'] ?? null,
                $data['pickup_date'] ?? null,
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['booking' => $exception->getMessage()]);
        }

        return back()->with('status', __('transport.booking.booked'));
    }
}
