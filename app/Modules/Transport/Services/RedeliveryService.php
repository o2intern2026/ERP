<?php

namespace App\Modules\Transport\Services;

use App\Models\User;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Support\TransportEnums;
use DomainException;
use Illuminate\Support\Facades\DB;

final class RedeliveryService
{
    /**
     * CHANGE_REQUESTS #135 (audit TMS-10): one redelivery per failed shipment — a second click (or a second coordinator) is
     * refused and the page links the existing one; the new number is `<original>-R1` (`-R2` when that attempt failed too),
     * so orders 0001–0009 no longer collapse into one prefix and the run / driver pages still identify the order.
     */
    public function create(Shipment $failedShipment, User $coordinator): Shipment
    {
        return DB::transaction(function () use ($failedShipment, $coordinator): Shipment {
            $failed = Shipment::query()->with('selectedQuote')->lockForUpdate()->findOrFail($failedShipment->id);
            $quote = $failed->selectedQuote;
            if (! TransportEnums::isDelivery($failed->shipment_type) || $failed->status !== 'failed' || $quote === null) {
                throw new DomainException(__('transport.redelivery.failed_only'));
            }
            $existing = Shipment::query()->where('redelivery_of_shipment_id', $failed->id)->lockForUpdate()->first();
            if ($existing !== null) {
                throw new DomainException(__('transport.redelivery.already_exists', ['shipment' => $existing->shipment_no]));
            }

            $shipment = Shipment::query()->create([
                'shipment_no' => self::nextNumber($failed->shipment_no),
                'job_id' => $failed->job_id,
                'client_id' => $failed->client_id,
                'order_id' => $failed->order_id,
                'asn_id' => $failed->asn_id, // a failed collection is re-attempted the same way (#124)
                'asn_activity_version' => $failed->asn_activity_version,
                'fulfilment_id' => $failed->fulfilment_id,
                'shipment_type' => $failed->shipment_type,
                'status' => 'quote_confirmed',
                'carrier_id' => $failed->carrier_id,
                'service_level' => $failed->service_level,
                'tailgate_required' => $failed->tailgate_required,
                'redelivery_of_shipment_id' => $failed->id,
            ]);
            $newQuote = TransportQuote::query()->create([
                'shipment_id' => $shipment->id,
                'carrier_id' => $quote->carrier_id,
                'source' => $quote->source,
                'service_level' => $quote->service_level,
                'cost_cents' => $quote->cost_cents,
                'customer_price_cents' => $quote->customer_price_cents,
                'markup_percent' => $quote->markup_percent,
                'eta_days' => $quote->eta_days,
                'is_recommended' => $quote->is_recommended,
                'is_cheapest' => $quote->is_cheapest,
                'is_fastest' => $quote->is_fastest,
                'quote_stage' => 'final',
                'status' => 'selected',
                'selected_by' => 'coordinator',
                'selected_by_user_id' => $coordinator->id,
                'quoted_at' => now(),
                'expires_at' => now()->addDay(),
                'raw_response' => ($quote->raw_response ?? []) + ['_redelivery_of' => $failed->id],
            ]);
            $shipment->update(['selected_quote_id' => $newQuote->id]);

            return $shipment->refresh();
        });
    }

    /** `SHP-ORD-20260921-0001` → `…-R1`; `…-R1` (failed again) → `…-R2`: the root is the number without any `-R<n>` suffix. */
    public static function nextNumber(string $failedNo): string
    {
        $root = (string) preg_replace('/-R\d+$/', '', $failedNo);
        $attempt = Shipment::query()
            ->where('shipment_no', 'like', $root.'-R%')
            ->pluck('shipment_no')
            ->map(fn (string $no): int => preg_match('/-R(\d+)$/', $no, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return $root.'-R'.($attempt + 1);
    }
}
