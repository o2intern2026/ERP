<?php

namespace App\Modules\Transport\Services;

use App\Models\User;
use App\Modules\Transport\Events\ShipmentQuoteConfirmed;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Support\TransportEnums;
use App\Support\Outbox\OutboxPublisher;
use App\Support\PackageUnits;
use App\Support\UrgentDespatch;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Selects preliminary estimates and confirms final carrier quotes atomically. */
final class QuoteSelectionService
{
    public function __construct(private readonly OutboxPublisher $outbox) {}

    /**
     * @param  DateTimeInterface|null  $confirmedAt  the moment the confirmation was decided. A person's click leaves it null (= now());
     *                                               an automatic confirmation (CHANGE_REQUESTS #118 / #120) passes the moment of the
     *                                               event that triggered it — the order's `confirmed_at` — so a final quote confirmed
     *                                               by the outbox consumer carries the client's decision time, not the cron / retry time,
     *                                               and `is_urgent` for a 提货直送 order is judged against the cut-off the way the 估价 was.
     */
    public function select(
        Shipment $shipment,
        TransportQuote $quote,
        string $selectedBy,
        ?int $selectedByUserId = null,
        ?DateTimeInterface $confirmedAt = null,
    ): Shipment {
        if (! in_array($selectedBy, TransportEnums::SELECTED_BY, true)) {
            throw new DomainException(__('transport.selection.invalid_selector'));
        }

        $this->validateActor($shipment, $selectedBy, $selectedByUserId);
        $confirmedAt = $confirmedAt === null ? null : CarbonImmutable::instance($confirmedAt);

        return DB::transaction(function () use ($shipment, $quote, $selectedBy, $selectedByUserId, $confirmedAt): Shipment {
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

            if (! TransportEnums::isDelivery($lockedShipment->shipment_type) || $lockedShipment->status !== 'quoted') {
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

            $confirmedAt ??= now();
            $lockedShipment->status = 'quote_confirmed';
            $lockedShipment->save();

            $this->outbox->publish(new ShipmentQuoteConfirmed(
                $this->payload($lockedShipment, $lockedQuote, $selectedBy, $selectedByUserId, $confirmedAt),
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
        CarbonInterface $confirmedAt,
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

        return array_merge([
            'shipment_id' => $shipment->id,
            'shipment_no' => $shipment->shipment_no,
            'shipment_type' => $shipment->shipment_type,
            'job_id' => $shipment->job_id,
            'client_id' => $shipment->client_id,
            'order_id' => $shipment->order_id,
            'asn_id' => $shipment->asn_id, // 我方上门提货 (CHANGE_REQUESTS #124): the 预报单 behind an inbound collection; null for order shipments
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
            'confirmed_at' => $confirmedAt->toIso8601String(),
        ], $this->orderContext($shipment, $confirmedAt), $this->collectionContext($shipment));
    }

    /**
     * Inbound collection only (CHANGE_REQUESTS #124): `activity_version` = the 预报单's collection_version the shipment was last
     * (re-)quoted for. A re-request before booking re-quotes and re-confirms the same shipment; carrying the version lets the
     * charge engine reverse the freight of the earlier confirmation instead of keeping the first price (cancel / redo = reversal
     * rows). A plain re-confirmation of the same request keeps the version, so it never re-bills. Absent for order shipments.
     *
     * @return array<string, mixed>
     */
    private function collectionContext(Shipment $shipment): array
    {
        return $shipment->isCollection() && $shipment->asn_activity_version !== null
            ? ['activity_version' => (int) $shipment->asn_activity_version]
            : [];
    }

    /**
     * Additive keys read from the order (CHANGE_REQUESTS #120): `order_type` for every shipment; for a 提货直送 (pickup_deliver)
     * order — no stock, no outbound.packed — also the client's declared packages as billing lines, so the handling charges
     * (order processing / urgent, picks per pallet or carton band, labels, load) arise from this event, and `is_urgent`
     * by the client's dispatch cut-off at the confirmation moment. from_stock shipments get none of those: their handling
     * charges come from outbound.packed.
     *
     * @return array<string, mixed>
     */
    private function orderContext(Shipment $shipment, CarbonInterface $confirmedAt): array
    {
        // An inbound collection (#124) has no order: order_type null, no handling keys — Billing's pickup_deliver rules never match.
        $order = $shipment->order_id !== null && Schema::hasTable('orders')
            ? DB::table('orders')->where('id', $shipment->order_id)->first(['order_type', 'requested_date', 'client_id'])
            : null;
        $orderType = $order === null || $order->order_type === null ? null : (string) $order->order_type;
        $context = ['order_type' => $orderType];

        if ($orderType !== 'pickup_deliver') {
            return $context;
        }

        $cutoff = DB::table('clients')->where('id', $order->client_id)->value('dispatch_cutoff_time');
        $lines = Schema::hasTable('declared_packages')
            ? DB::table('declared_packages')
                ->where('order_id', $shipment->order_id)
                ->orderBy('id')
                ->get()
                ->map(fn (object $package): array => [
                    'package_type' => (string) $package->package_type,
                    'unit_type' => PackageUnits::unitType($package->package_type),
                    'qty' => max(0, (int) $package->qty),
                    'unit_weight_kg' => (float) ($package->weight_kg ?? 0),
                ])
                ->values()
                ->all()
            : [];

        $count = fn (?string $unitType): int => array_sum(array_map(
            fn (array $line): int => $unitType === null || $line['unit_type'] === $unitType ? $line['qty'] : 0,
            $lines,
        ));

        $context['is_urgent'] = UrgentDespatch::isUrgent(
            isset($order->requested_date) ? (string) $order->requested_date : null,
            $cutoff === null ? null : (string) $cutoff,
            $confirmedAt,
        );
        $context['lines'] = $lines;
        $context['pallet_count'] = $count('pallet');
        $context['carton_count'] = $count('carton');
        $context['label_count'] = $count(null);

        return $context;
    }
}
