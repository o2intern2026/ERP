<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Events\ShipmentBooked;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Support\Contracts\CarrierAdapter;
use App\Support\Contracts\ExceptionService;
use App\Support\Outbox\OutboxPublisher;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ShipmentBookingService
{
    /** @var array<string, CarrierAdapter> */
    private array $adapters = [];

    /** @param iterable<CarrierAdapter> $adapters */
    public function __construct(
        iterable $adapters,
        private readonly ShipmentQuoteRequestFactory $requests,
        private readonly ExceptionService $exceptions,
        private readonly CarrierCostService $costs,
        private readonly OutboxPublisher $outbox,
    ) {
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->source()] = $adapter;
        }
    }

    public function book(
        Shipment $shipment,
        ?string $manualReference = null,
        ?string $trackingNumber = null,
        ?string $pickupDate = null,
    ): Shipment {
        $shipment->loadMissing('selectedQuote');
        if ($shipment->status === 'booked') {
            return $shipment;
        }

        if ($this->exceptions->hasActiveHold('financial', $shipment->client_id, $shipment->order_id)) {
            throw new DomainException(__('transport.booking.financial_hold'));
        }

        $quote = $shipment->selectedQuote;
        $this->assertBookable($shipment, $quote);
        $adapter = $this->adapters[$quote->source] ?? null;
        if ($adapter === null || ! ($adapter->capabilities()['book'] ?? false)) {
            throw new DomainException(__('transport.booking.adapter_unavailable'));
        }

        // Gateways that book against the carrier need the full consignment (integrator edit for karrio, CHANGE_REQUESTS #58).
        $request = in_array($quote->source, ['transdirect', 'karrio'], true) ? $this->requests->build($shipment, 'final') : [];
        if ($request === null) {
            throw new DomainException(__('transport.booking.details_unavailable'));
        }
        if ($trackingNumber !== null) {
            $request['tracking_number'] = trim($trackingNumber);
        }

        $raw = $quote->raw_response ?? [];
        $serviceCode = (string) (data_get($raw, '_booking.service_code') ?: $quote->service_level);
        $quoteReference = trim((string) ($manualReference
            ?: data_get($raw, '_booking.quote_ref')
            ?: ($raw['booking_id'] ?? '')));

        try {
            $result = $adapter->book($request, $serviceCode, [
                'quote_ref' => $quoteReference,
                'pickup_date' => $pickupDate ?: data_get($raw, '_booking.pickup_dates.0'),
            ]);
        } catch (Throwable $exception) {
            return $this->bookingFailure($shipment, $exception->getMessage());
        }

        $bookingRef = trim((string) ($result['booking_ref'] ?? ''));
        $carrierStatus = trim((string) ($result['status'] ?? ''));
        if ($bookingRef === '' || in_array($carrierStatus, ['', 'new', 'pending_payment', 'pending_review', 'request_failed', 'cancelled'], true)) {
            return $this->bookingFailure($shipment, $carrierStatus ?: __('transport.booking.unknown_status'));
        }

        return DB::transaction(function () use ($shipment, $quote, $result, $bookingRef): Shipment {
            $lockedShipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            if ($lockedShipment->status === 'booked') {
                return $lockedShipment->refresh();
            }

            $lockedQuote = TransportQuote::query()->lockForUpdate()->findOrFail($quote->id);
            $this->assertBookable($lockedShipment, $lockedQuote);
            $bookedAt = now();
            $lockedShipment->fill([
                'status' => 'booked',
                'booking_ref' => $bookingRef,
                'tracking_number' => ($result['tracking_number'] ?? null) ?: null,
            ])->save();

            if ($lockedQuote->source !== 'own_fleet') {
                $this->costs->recordExpected($lockedShipment, $lockedQuote);
            }

            $this->outbox->publish(new ShipmentBooked([
                'shipment_id' => $lockedShipment->id,
                'shipment_no' => $lockedShipment->shipment_no,
                'job_id' => $lockedShipment->job_id,
                'client_id' => $lockedShipment->client_id,
                'order_id' => $lockedShipment->order_id,
                'carrier_id' => $lockedQuote->carrier_id,
                'source' => $lockedQuote->source,
                'service_level' => $lockedQuote->service_level,
                'booking_ref' => $lockedShipment->booking_ref,
                'tracking_number' => $lockedShipment->tracking_number,
                'waybill_document_id' => $lockedShipment->waybill_document_id,
                'expected_cost_cents' => $lockedQuote->cost_cents,
                'delivery_run_id' => $lockedShipment->delivery_run_id,
                'booked_at' => $bookedAt->toIso8601String(),
            ], jobId: $lockedShipment->job_id, clientId: $lockedShipment->client_id, correlationId: $lockedShipment->shipment_no));

            return $lockedShipment->refresh();
        });
    }

    private function assertBookable(Shipment $shipment, ?TransportQuote $quote): void
    {
        if ($shipment->shipment_type !== 'outbound'
            || $shipment->status !== 'quote_confirmed'
            || $quote === null
            || $quote->shipment_id !== $shipment->id
            || $quote->id !== $shipment->selected_quote_id
            || $quote->quote_stage !== 'final'
            || $quote->status !== 'selected') {
            throw new DomainException(__('transport.booking.invalid_status'));
        }
    }

    private function bookingFailure(Shipment $shipment, string $reason): never
    {
        $this->exceptions->raise('manual_transport', 'transport', [
            'job_id' => $shipment->job_id,
            'client_id' => $shipment->client_id,
            'order_id' => $shipment->order_id,
            'source_type' => 'shipment',
            'source_id' => $shipment->id,
            'message' => __('transport.booking.failed', ['reason' => $reason]),
        ]);

        throw new DomainException(__('transport.booking.failed', ['reason' => $reason]));
    }
}
