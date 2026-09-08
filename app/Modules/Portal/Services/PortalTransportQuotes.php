<?php

namespace App\Modules\Portal\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Transport\Models\Shipment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ERP_PLAN §5.7 #2 / §7 step 4 in the portal: the client's outbound shipments with their final carrier quotes, so the
 * client can confirm the recommended option or pick another one. Customer fields only — `cost_cents` and `markup_percent`
 * are never selected, so they cannot reach the view or a client user. Read-only on Transport's tables; the confirmation
 * itself goes through Transport's `QuoteSelectionService` (contracts/services.md §3).
 */
final class PortalTransportQuotes
{
    /** Quote columns a client may see (never cost / markup). */
    private const CUSTOMER_COLUMNS = ['q.id', 'q.shipment_id', 'q.source', 'q.service_level', 'q.customer_price_cents', 'q.eta_days', 'q.is_recommended', 'q.is_cheapest', 'q.is_fastest', 'q.status', 'q.selected_by', 'q.quoted_at', 'q.expires_at', 'c.name as carrier_name'];

    /**
     * Every outbound batch shipment of the order (fulfilment_id set — one per packed batch, §3.8 #8), else the preliminary
     * shipment Transport opened at confirmation. Each entry carries its final quotes (`quoted` → confirmable, `selected` →
     * the confirmed choice) and whether the client can still confirm (shipment status `quoted`).
     *
     * @return list<array{shipment: Shipment, quotes: Collection<int, object>, selected: ?object, can_confirm: bool}>
     */
    public function forOrder(Order $order): array
    {
        $shipments = $this->outbound($order)->whereNotNull('fulfilment_id')->orderByDesc('id')->get();
        if ($shipments->isEmpty()) {
            $shipments = $this->outbound($order)->orderByDesc('id')->limit(1)->get();
        }
        if ($shipments->isEmpty()) {
            return [];
        }

        $quotes = DB::table('transport_quotes as q')
            ->leftJoin('carriers as c', 'c.id', '=', 'q.carrier_id')
            ->whereIn('q.shipment_id', $shipments->pluck('id'))
            ->where('q.quote_stage', 'final')
            ->whereIn('q.status', ['quoted', 'selected'])
            ->orderByDesc('q.status') // the selected quote first
            ->orderByDesc('q.is_recommended')
            ->orderBy('q.customer_price_cents')
            ->orderByDesc('q.id')
            ->get(self::CUSTOMER_COLUMNS)
            ->groupBy('shipment_id');

        return $shipments->map(function (Shipment $shipment) use ($quotes): array {
            $own = $quotes->get($shipment->id, collect());

            return [
                'shipment' => $shipment,
                'quotes' => $own,
                'selected' => $own->first(fn ($q) => $q->status === 'selected' || (int) $q->id === (int) $shipment->selected_quote_id),
                'can_confirm' => $shipment->status === 'quoted',
            ];
        })->values()->all();
    }

    /** Client-scoped (Shipment uses BelongsToClient): another client's shipments never appear. */
    private function outbound(Order $order)
    {
        return Shipment::query()->where('order_id', $order->id)->where('shipment_type', 'outbound')->with('carrier');
    }
}
