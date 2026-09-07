<?php

namespace App\Modules\Transport\Services;

use App\Models\User;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RedeliveryService
{
    public function create(Shipment $failedShipment, User $coordinator): Shipment
    {
        return DB::transaction(function () use ($failedShipment, $coordinator): Shipment {
            $failed = Shipment::query()->with('selectedQuote')->lockForUpdate()->findOrFail($failedShipment->id);
            $quote = $failed->selectedQuote;
            if ($failed->shipment_type !== 'outbound' || $failed->status !== 'failed' || $quote === null) {
                throw new DomainException(__('transport.redelivery.failed_only'));
            }

            $shipment = Shipment::query()->create([
                'shipment_no' => Str::limit($failed->shipment_no, 20, '').'-R-'.Str::upper(Str::random(6)),
                'job_id' => $failed->job_id,
                'client_id' => $failed->client_id,
                'order_id' => $failed->order_id,
                'fulfilment_id' => $failed->fulfilment_id,
                'shipment_type' => 'outbound',
                'status' => 'quote_confirmed',
                'carrier_id' => $failed->carrier_id,
                'service_level' => $failed->service_level,
                'tailgate_required' => $failed->tailgate_required,
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
}
