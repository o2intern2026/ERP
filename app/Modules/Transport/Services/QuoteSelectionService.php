<?php

namespace App\Modules\Transport\Services;

use App\Models\User;
use App\Modules\Transport\Events\ShipmentQuoteConfirmed;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Support\TransportEnums;
use App\Support\Outbox\OutboxPublisher;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Selects preliminary estimates and confirms final carrier quotes atomically. */
final class QuoteSelectionService
{
    public function __construct(private readonly OutboxPublisher $outbox) {}

    public function select(
        Shipment $shipment,
        TransportQuote $quote,
        string $selectedBy,
        ?int $selectedByUserId = null,
    ): Shipment {
        if (! in_array($selectedBy, TransportEnums::SELECTED_BY, true)) {
            throw new DomainException(__('transport.selection.invalid_selector'));
        }

        $this->validateActor($shipment, $selectedBy, $selectedByUserId);

        return DB::transaction(function () use ($shipment, $quote, $selectedBy, $selectedByUserId): Shipment {
            $lockedShipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->getKey());
            $lockedQuote = TransportQuote::query()->lockForUpdate()->findOrFail($quote->getKey());

            if ($lockedQuote->shipment_id !== $lockedShipment->id) {
                throw new DomainException(__('transport.selection.wrong_shipment'));
            }

            if ($lockedQuote->status === 'selected' && $lockedShipment->selected_quote_id === $lockedQuote->id) {
                return $lockedShipment->refresh();
            }

            if ($lockedQuote->status !== 'quoted') {
                throw new DomainException(__('transport.selection.unavailable'));
            }

            if ($lockedQuote->expires_at->isPast()) {
                throw new DomainException(__('transport.selection.expired'));
            }

            if ($lockedShipment->shipment_type !== 'outbound' || $lockedShipment->status !== 'quoted') {
                throw new DomainException(__('transport.selection.invalid_status'));
            }

            TransportQuote::query()
                ->where('shipment_id', $lockedShipment->id)
                ->where('quote_stage', $lockedQuote->quote_stage)
                ->where('status', 'selected')
                ->whereKeyNot($lockedQuote->id)
                ->update(['status' => 'requoted']);

            $lockedQuote->update([
                'status' => 'selected',
                'selected_by' => $selectedBy,
                'selected_by_user_id' => $selectedByUserId,
            ]);

            $lockedShipment->fill([
                'selected_quote_id' => $lockedQuote->id,
                'carrier_id' => $lockedQuote->carrier_id,
                'service_level' => $lockedQuote->service_level,
            ]);

            if ($lockedQuote->quote_stage === 'preliminary') {
                $lockedShipment->save();

                return $lockedShipment->refresh();
            }

            $confirmedAt = now();
            $lockedShipment->status = 'quote_confirmed';
            $lockedShipment->save();

            $this->outbox->publish(new ShipmentQuoteConfirmed(
                $this->payload($lockedShipment, $lockedQuote, $selectedBy, $selectedByUserId, $confirmedAt->toIso8601String()),
                jobId: $lockedShipment->job_id,
                clientId: $lockedShipment->client_id,
                correlationId: $lockedShipment->shipment_no,
            ));

            return $lockedShipment->refresh();
        });
    }

    private function validateActor(Shipment $shipment, string $selectedBy, ?int $userId): void
    {
        if ($selectedBy === 'system') {
            if ($userId !== null) {
                throw new DomainException(__('transport.selection.invalid_actor'));
            }

            return;
        }

        if ($userId === null) {
            throw new DomainException(__('transport.selection.invalid_actor'));
        }

        $user = User::query()->find($userId);
        if ($user === null || ! $user->is_active) {
            throw new DomainException(__('transport.selection.invalid_actor'));
        }

        if ($selectedBy === 'client') {
            if (! $user->isClientUser() || (int) $user->client_id !== (int) $shipment->client_id) {
                throw new DomainException(__('transport.selection.invalid_actor'));
            }

            return;
        }

        if ($user->isClientUser()) {
            throw new DomainException(__('transport.selection.invalid_actor'));
        }
    }

    /** @return array<string, mixed> */
    private function payload(
        Shipment $shipment,
        TransportQuote $quote,
        string $confirmedByType,
        ?int $confirmedBy,
        string $confirmedAt,
    ): array {
        $raw = $quote->raw_response ?? [];
        $request = is_array($raw['_quote_request'] ?? null) ? $raw['_quote_request'] : [];
        $items = is_array($request['items'] ?? null) ? $request['items'] : [];
        $count = 0;
        $weight = 0.0;
        $cbm = 0.0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $qty = max(0, (int) ($item['qty'] ?? 0));
            $count += $qty;
            $weight += $qty * (float) ($item['weight_kg'] ?? 0);
            $cbm += $qty
                * (int) ($item['length_mm'] ?? 0)
                * (int) ($item['width_mm'] ?? 0)
                * (int) ($item['height_mm'] ?? 0)
                / 1_000_000_000;
        }

        $pricingMode = $raw['pricing_mode'] ?? ($quote->source === 'own_fleet' ? 'fixed' : 'cost_plus');
        if (! in_array($pricingMode, ['fixed', 'cost_plus'], true)) {
            $pricingMode = $quote->source === 'own_fleet' ? 'fixed' : 'cost_plus';
        }

        return [
            'shipment_id' => $shipment->id,
            'shipment_no' => $shipment->shipment_no,
            'shipment_type' => $shipment->shipment_type,
            'job_id' => $shipment->job_id,
            'client_id' => $shipment->client_id,
            'order_id' => $shipment->order_id,
            'fulfilment_id' => $shipment->fulfilment_id,
            'transport_quote_id' => $quote->id,
            'quote_stage' => $quote->quote_stage,
            'source' => $quote->source,
            'carrier_id' => $quote->carrier_id,
            'service_level' => $quote->service_level,
            'pricing_mode' => $pricingMode,
            'cost_cents' => $quote->cost_cents,
            'customer_price_cents' => $quote->customer_price_cents,
            'markup_percent' => $quote->markup_percent === null ? null : (float) $quote->markup_percent,
            'eta_days' => $quote->eta_days,
            'tailgate_required' => $shipment->tailgate_required,
            'zone' => (string) ($request['zone'] ?? ''),
            'packages' => [
                'count' => $count,
                'total_weight_kg' => round($weight, 3),
                'total_cbm' => round($cbm, 6),
            ],
            'confirmed_by_type' => $confirmedByType,
            'confirmed_by' => $confirmedBy,
            'confirmed_at' => $confirmedAt,
        ];
    }
}
