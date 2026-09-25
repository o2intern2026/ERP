<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Support\TransportEnums;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #160 / #161 (integrator edit, HANDOFF 2026-09-25): 批量确认最终方案 and 批量确认预订 on the transport board.
 * Every ticked shipment goes through the SAME single-shipment service call as the buttons on its page — QuoteSelectionService::select
 * as 'coordinator' (代客确认), ShipmentBookingService::book — so the rules, events and refusals are the single buttons'; refused
 * shipments are named with the reason, the rest go through.
 */
final class ShipmentBulkActionService
{
    public function __construct(
        private readonly QuoteSelectionService $selection,
        private readonly ShipmentBookingService $bookings,
    ) {}

    /**
     * The final quote 批量确认 would confirm for a 已报价 delivery shipment, and why: the valid final quote (quoted, not expired) of the
     * option committed to before — the selected preliminary quote, else the plan the client chose with the 估价 (`orders.transport_preference`
     * / `asns.collection_preference`, the same reference TransportOptionService resolves automatically) — else the recommended final quote.
     * Null when there is no valid final quote, or none is the client's plan or recommended: that shipment waits for a person on its page.
     *
     * @return array{quote: TransportQuote, basis: 'client'|'recommended'}|null
     */
    public function confirmable(Shipment $shipment): ?array
    {
        if (! TransportEnums::isDelivery($shipment->shipment_type) || $shipment->status !== 'quoted') {
            return null;
        }

        $finals = $this->validFinals($shipment);
        if ($finals->isEmpty()) {
            return null;
        }

        $reference = $this->reference($shipment);
        $match = $reference === null ? null : $this->matching($finals, $reference);
        if ($match !== null) {
            return ['quote' => $match, 'basis' => 'client'];
        }

        $recommended = $finals->first(fn (TransportQuote $quote): bool => (bool) $quote->is_recommended);

        return $recommended === null ? null : ['quote' => $recommended, 'basis' => 'recommended'];
    }

    /**
     * @param  list<int>  $shipmentIds
     * @return array{done: list<string>, refused: array<string, string>} numbers confirmed; refused number => Chinese reason
     */
    public function confirmQuotes(array $shipmentIds, int $userId): array
    {
        $done = [];
        $refused = [];
        foreach ($this->shipments($shipmentIds) as $shipment) {
            $confirmable = $this->confirmable($shipment);
            if ($confirmable === null) {
                $refused[$shipment->shipment_no] = $this->confirmRefusal($shipment);

                continue;
            }

            try {
                $this->selection->select($shipment, $confirmable['quote'], 'coordinator', $userId);
                $done[] = $shipment->shipment_no;
            } catch (DomainException $exception) {
                $refused[$shipment->shipment_no] = $exception->getMessage();
            }
        }

        return ['done' => $done, 'refused' => $refused];
    }

    /** Whether 批量预订 offers the shipment: 报价已确认 delivery with its selected final quote (the booking service's own gate). */
    public function bookable(Shipment $shipment): bool
    {
        $quote = $shipment->selectedQuote;

        return TransportEnums::isDelivery($shipment->shipment_type)
            && $shipment->status === 'quote_confirmed'
            && $quote !== null
            && $quote->quote_stage === 'final'
            && $quote->status === 'selected';
    }

    /**
     * Books each ticked shipment as the single 确认预订 button does without a reference, tracking number or pickup date (Transdirect
     * takes the quote's first pickup date). A manual quote without a stored reference is refused BEFORE the booking service, which
     * would otherwise raise a manual_transport exception for it — that shipment is booked on its page with the reference typed in.
     *
     * @param  list<int>  $shipmentIds
     * @return array{done: list<string>, refused: array<string, string>}
     */
    public function book(array $shipmentIds): array
    {
        $done = [];
        $refused = [];
        foreach ($this->shipments($shipmentIds) as $shipment) {
            if (! $this->bookable($shipment)) {
                $refused[$shipment->shipment_no] = __('transport.booking.invalid_status');

                continue;
            }

            $raw = $shipment->selectedQuote->raw_response ?? [];
            if ($shipment->selectedQuote->source === 'manual'
                && trim((string) (data_get($raw, '_booking.quote_ref') ?: ($raw['booking_id'] ?? ''))) === '') {
                $refused[$shipment->shipment_no] = __('transport.bulk_book.manual_reference');

                continue;
            }

            try {
                $this->bookings->book($shipment);
                $done[] = $shipment->shipment_no;
            } catch (DomainException $exception) {
                $refused[$shipment->shipment_no] = $exception->getMessage();
            }
        }

        return ['done' => $done, 'refused' => $refused];
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Shipment>
     */
    private function shipments(array $ids): Collection
    {
        return Shipment::query()
            ->with(['quotes', 'selectedQuote'])
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, TransportQuote> final quotes still open for selection, oldest first */
    private function validFinals(Shipment $shipment): Collection
    {
        return $shipment->quotes
            ->filter(fn (TransportQuote $quote): bool => $quote->quote_stage === 'final'
                && $quote->status === 'quoted'
                && $quote->expires_at !== null
                && $quote->expires_at->isFuture())
            ->sortBy('id')
            ->values();
    }

    private function confirmRefusal(Shipment $shipment): string
    {
        if (! TransportEnums::isDelivery($shipment->shipment_type) || $shipment->status !== 'quoted') {
            return __('transport.selection.invalid_status');
        }
        if ($shipment->quotes->where('quote_stage', 'final')->isEmpty()) {
            return __('transport.bulk_confirm.no_final_quote');
        }
        if ($this->validFinals($shipment)->isNotEmpty()) {
            return __('transport.bulk_confirm.no_reference');
        }

        return __('transport.selection.expired');
    }

    /**
     * What was committed to: the selected preliminary quote, else the client's 估价 choice (customer fields only, read-only).
     *
     * @return array<string, mixed>|null
     */
    private function reference(Shipment $shipment): ?array
    {
        $preliminary = $shipment->quotes->first(fn (TransportQuote $quote): bool => $quote->quote_stage === 'preliminary' && $quote->status === 'selected');
        if ($preliminary !== null) {
            return ['source' => $preliminary->source, 'service_level' => $preliminary->service_level, 'carrier_id' => $preliminary->carrier_id];
        }

        if ($shipment->order_id !== null) {
            $raw = Schema::hasColumn('orders', 'transport_preference')
                ? DB::table('orders')->where('id', $shipment->order_id)->value('transport_preference')
                : null;
        } elseif ($shipment->asn_id !== null) {
            $raw = Schema::hasColumn('asns', 'collection_preference')
                ? DB::table('asns')->where('id', $shipment->asn_id)->value('collection_preference')
                : null;
        } else {
            return null;
        }
        $preference = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);

        return is_array($preference) && isset($preference['source'], $preference['service_level']) ? $preference : null;
    }

    /**
     * The quote for the same option: exact carrier first, then the same source + service level (as TransportOptionService matches).
     *
     * @param  Collection<int, TransportQuote>  $quotes
     * @param  array<string, mixed>  $reference
     */
    private function matching(Collection $quotes, array $reference): ?TransportQuote
    {
        $same = $quotes->filter(fn (TransportQuote $quote): bool => $quote->source === ($reference['source'] ?? null)
            && $quote->service_level === ($reference['service_level'] ?? null));
        $carrierId = (int) ($reference['carrier_id'] ?? 0);

        return ($carrierId > 0 ? $same->first(fn (TransportQuote $quote): bool => (int) $quote->carrier_id === $carrierId) : null) ?? $same->first();
    }
}
