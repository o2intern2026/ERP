<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A14 / OMS-12: one import (container or truck) fans out into many delivery orders; this ties them back together
 * through order_lines.asn_line_id → Warehouse's asn_lines / containers (read-only). Revenue comes from Billing's
 * charges per Job once that module is merged (M6); until then the total is shown as pending.
 */
final class OrderBatchService
{
    /**
     * @return array{asns: Collection, containers: Collection, orders: Collection, totals: array{orders:int, cartons:int, shipped:int, by_status:array<string,int>, revenue_cents:?int}}
     */
    public function lookup(string $reference): array
    {
        $reference = trim($reference);
        $asnIds = DB::table('asns')->where('asn_no', $reference)->pluck('id')
            ->merge(DB::table('containers')->where('container_no', $reference)->pluck('asn_id'))
            ->unique()->values();

        if ($asnIds->isEmpty()) {
            return ['asns' => collect(), 'containers' => collect(), 'orders' => collect(), 'totals' => ['orders' => 0, 'cartons' => 0, 'shipped' => 0, 'by_status' => [], 'revenue_cents' => null]];
        }

        $lineIds = DB::table('asn_lines')->whereIn('asn_id', $asnIds)->pluck('id');
        $orders = Order::query()->with(['client', 'job', 'lines'])
            ->whereHas('lines', fn ($q) => $q->whereIn('asn_line_id', $lineIds))
            ->orderBy('id')->get();
        $batchLines = $orders->flatMap->lines->filter(fn ($line) => $lineIds->contains($line->asn_line_id));

        $revenue = null;
        if (Schema::hasTable('charges') && $orders->isNotEmpty()) {
            $revenue = (int) DB::table('charges')->whereIn('job_id', $orders->pluck('job_id')->unique())->where('status', '!=', 'reversed')->whereNull('reversal_of_charge_id')->sum('amount_cents');
        }

        return [
            'asns' => DB::table('asns')->whereIn('id', $asnIds)->get(),
            'containers' => DB::table('containers')->whereIn('asn_id', $asnIds)->get(),
            'orders' => $orders,
            'totals' => [
                'orders' => $orders->count(),
                'cartons' => (int) $batchLines->sum('carton_qty'),
                'shipped' => (int) $batchLines->sum('qty_shipped'),
                'by_status' => $orders->countBy('operational_status')->all(),
                'revenue_cents' => $revenue,
            ],
        ];
    }

    /** @return array<int, object{asn_no: string, container_no: ?string}> asn_line_id → its ASN / container for the order's lines */
    public function asnRefsFor(Order $order): array
    {
        $lineIds = $order->lines->pluck('asn_line_id')->filter()->unique();
        if ($lineIds->isEmpty()) {
            return [];
        }

        return DB::table('asn_lines')
            ->join('asns', 'asns.id', '=', 'asn_lines.asn_id')
            ->leftJoin('containers', 'containers.id', '=', 'asn_lines.container_id')
            ->whereIn('asn_lines.id', $lineIds)
            ->get(['asn_lines.id as asn_line_id', 'asns.asn_no', 'containers.container_no'])
            ->keyBy('asn_line_id')->all();
    }
}
