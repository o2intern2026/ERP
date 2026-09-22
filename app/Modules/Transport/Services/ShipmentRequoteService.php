<?php

namespace App\Modules\Transport\Services;

use App\Models\User;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Support\TransportEnums;
use App\Support\Contracts\TransportOptionService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 重新报价 (CHANGE_REQUESTS #133, audit TMS-02): quotes are valid for 24 h and used to be produced only by the event consumers
 * (order confirmed / packed / ASN collection), so a shipment packed on Friday had nothing selectable on Monday. A planner
 * re-runs TransportOptionService::quote() for the shipment's latest stage; the same client-preference / variance rule then
 * applies as when the event came in, so a client's chosen option within tolerance is confirmed again without a click.
 */
final class ShipmentRequoteService
{
    /** A booked / dispatched / delivered shipment is never re-quoted — the carrier already has it. */
    public const REQUOTABLE_STATUSES = ['quoting', 'quoted', 'quote_confirmed'];

    public function __construct(private readonly TransportOptionService $quotes) {}

    /** @return array{stage: string, count: int} */
    public function requote(Shipment $shipment, ?User $user = null): array
    {
        $stage = DB::transaction(function () use ($shipment): string {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            if (! TransportEnums::isDelivery($locked->shipment_type) || ! in_array($locked->status, self::REQUOTABLE_STATUSES, true)) {
                throw new DomainException(__('transport.quotes.requote_invalid_status'));
            }
            $stage = $this->latestStage($locked);

            // Dead quotes are marked expired (quote() would call them requoted); live ones of the stage become requoted there.
            TransportQuote::query()
                ->where('shipment_id', $locked->id)
                ->where('status', 'quoted')
                ->where('expires_at', '<=', now())
                ->update(['status' => 'expired']);

            // A confirmed but unbooked selection is history: the shipment waits in `quoted` and the final stage re-runs the
            // client-preference / variance rule — the same option within tolerance is confirmed again in the same name.
            if ($locked->status === 'quote_confirmed') {
                TransportQuote::query()
                    ->where('shipment_id', $locked->id)
                    ->where('quote_stage', 'final')
                    ->where('status', 'selected')
                    ->update(['status' => 'requoted']);
                $locked->fill(['status' => 'quoted', 'selected_quote_id' => null, 'carrier_id' => null, 'service_level' => null])->save();
            }

            return $stage;
        });

        // Outside the transaction, as the event consumers do: the adapters may call a carrier gateway.
        $quotes = $this->quotes->quote($shipment->id, $stage);

        $activity = activity('shipment')
            ->performedOn($shipment)
            ->withProperties(['attributes' => ['stage' => $stage, 'quotes' => count($quotes)]]);
        if ($user !== null) {
            $activity->causedBy($user);
        }
        $activity->log(__('transport.quotes.log.requoted'));

        return ['stage' => $stage, 'count' => count($quotes)];
    }

    /**
     * The stage the shipment is at: final once any final quote exists, for an inbound collection (the declared packages are the
     * final list, #124) or once packed (fulfilment known); preliminary while only preliminary quotes exist; with no quote at all
     * (no automatic option when the event came in) a 提货直送 order is final and a from_stock order preliminary, as the intake decides.
     */
    private function latestStage(Shipment $shipment): string
    {
        if ($shipment->isCollection()
            || $shipment->fulfilment_id !== null
            || TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->exists()) {
            return 'final';
        }
        if (TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'preliminary')->exists()) {
            return 'preliminary';
        }
        $orderType = $shipment->order_id !== null && Schema::hasTable('orders')
            ? DB::table('orders')->where('id', $shipment->order_id)->value('order_type')
            : null;

        return $orderType === 'pickup_deliver' ? 'final' : 'preliminary';
    }
}
