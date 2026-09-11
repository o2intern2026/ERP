<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Support\Contracts\InboundService;
use App\Support\Contracts\JobService;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 从订单生成预报单 (lead decision 2026-09-11, CHANGE_REQUESTS #117): the client's orders are the upstream document. When the goods
 * are announced, staff pick the orders that arrive together and one ASN is opened for them through Warehouse's InboundService —
 * one goods line per order line, linked in both directions — so receiving → putaway allocates the orders on its own (backorders).
 * The orders share one Job afterwards: the first order's Job hosts the ASN, the others are merged into it and their emptied
 * per-order Jobs are cancelled. No warehouse rule changes; the coordinator's manual ASN and 从预报单生成派送订单 stay for goods that
 * arrive before any order exists.
 */
final class OrderInboundService
{
    public const ROLES = ['admin', 'customer_service', 'warehouse_supervisor'];

    /** Orders that still need an ASN: from_stock, not yet picking, at least one goods line without an ASN line. */
    public const ELIGIBLE_STATUSES = ['received', 'confirmed'];

    public function __construct(
        private readonly InboundService $inbound,
        private readonly JobService $jobs,
        private readonly OrderStatusService $statuses,
    ) {}

    /** @return Collection<int, Order> */
    public function candidates(?int $clientId = null): Collection
    {
        return $this->eligible()->with(['client', 'job', 'lines'])
            ->when($clientId, fn ($query, $id) => $query->where('client_id', $id))
            ->orderBy('client_id')->orderBy('job_id')->orderBy('id')->get();
    }

    public function pendingCount(): int
    {
        return $this->eligible()->count();
    }

    /**
     * @param  list<int>  $orderIds
     * @param  array{warehouse_id:int|string, inbound_type:string, expected_date?:?string, notes?:?string, container_no?:?string, container_size?:?string, unpack_mode?:?string, gross_weight_kg?:mixed}  $header
     * @return array{asn_id:int, asn_no:string, job_id:int, job_no:string, orders:int, lines:int, merged:list<string>, cancelled:list<string>}
     */
    public function generate(array $orderIds, array $header, ?int $actorId): array
    {
        return DB::transaction(function () use ($orderIds, $header, $actorId): array {
            $orders = Order::query()->with(['lines', 'job'])->whereKey($orderIds)->lockForUpdate()->orderBy('id')->get();
            if ($orders->isEmpty()) {
                throw new RuleViolation('No orders selected.', 'orders.inbound.errors.none_selected');
            }
            if ($orders->pluck('client_id')->unique()->count() > 1) {
                throw new RuleViolation('Orders of different clients cannot share an ASN.', 'orders.inbound.errors.mixed_clients');
            }
            foreach ($orders as $order) {
                if ($order->order_type !== 'from_stock' || ! in_array($order->operational_status, self::ELIGIBLE_STATUSES, true)) {
                    throw new RuleViolation("Order {$order->order_no} is not eligible for an ASN.", 'orders.inbound.errors.order_not_eligible', ['order_no' => $order->order_no]);
                }
                if ($order->lines->isEmpty() || $order->lines->every(fn (OrderLine $l) => $l->asn_line_id !== null)) {
                    throw new RuleViolation("Order {$order->order_no} already has its goods on an ASN.", 'orders.inbound.errors.already_linked', ['order_no' => $order->order_no]);
                }
            }

            // One Job for the shipment: the first order's; the others move in, their emptied Jobs are cancelled.
            $master = $orders->first();
            $jobId = (int) $master->job_id;
            $merged = [];
            $cancelled = [];
            foreach ($orders as $order) {
                if ((int) $order->job_id === $jobId) {
                    continue;
                }
                if (DB::table('asns')->where('job_id', $order->job_id)->exists()) {
                    throw new RuleViolation("The Job of {$order->order_no} already carries an ASN.", 'orders.inbound.errors.job_has_asn', ['order_no' => $order->order_no, 'job_no' => $order->job->job_no]);
                }
                $oldJobId = (int) $order->job_id;
                $oldJobNo = $order->job->job_no;
                Order::query()->withoutGlobalScopes()->whereKey($order->id)->update(['job_id' => $jobId]);
                $merged[] = $order->order_no;
                if ($this->jobs->cancelIfEmpty($oldJobId, __('orders.inbound.job_cancelled_note', ['job_no' => $master->job->job_no, 'order_no' => $order->order_no]))) {
                    $cancelled[] = $oldJobNo;
                }
            }

            $container = $header['inbound_type'] === 'container' && filled($header['container_no'] ?? null) ? trim((string) $header['container_no']) : null;
            $lines = [];
            foreach ($orders as $order) {
                foreach ($order->lines->whereNull('asn_line_id') as $line) {
                    $lines[] = [
                        'order_line_id' => $line->id,
                        'container_no' => $container,
                        'consignment_mark' => $order->consignment_mark,
                        'description' => trim(implode(' / ', array_filter([$line->description_cn, $line->description_en]))) ?: '—',
                        'expected_cartons' => (int) $line->carton_qty,
                        'package_type' => $line->package_type,
                        'deliver_to_name' => $order->deliver_to_name,
                        'deliver_to_phone' => $order->deliver_to_phone,
                        'deliver_to_address' => $order->deliver_to_address,
                        'deliver_to_suburb' => $order->deliver_to_suburb,
                        'deliver_to_state' => $order->deliver_to_state,
                        'deliver_to_postcode' => $order->deliver_to_postcode,
                        'fba_reference' => $order->fba_reference,
                        'weight_kg' => $line->actual_weight_kg,
                        'length_mm' => $line->length_mm,
                        'width_mm' => $line->width_mm,
                        'height_mm' => $line->height_mm,
                        'cbm' => $line->cbm,
                    ];
                }
            }

            $result = $this->inbound->createAsnFromOrderLines([
                'client_id' => (int) $master->client_id,
                'warehouse_id' => (int) $header['warehouse_id'],
                'job_id' => $jobId,
                'inbound_type' => $header['inbound_type'],
                'expected_date' => filled($header['expected_date'] ?? null) ? $header['expected_date'] : null,
                'notes' => trim(implode("\n", array_filter([$header['notes'] ?? null, __('orders.inbound.asn_note', ['orders' => $orders->pluck('order_no')->implode(', ')])]))),
                'containers' => $container === null ? [] : [[
                    'container_no' => $container,
                    'size' => $header['container_size'] ?? '40',
                    'unpack_mode' => filled($header['unpack_mode'] ?? null) ? $header['unpack_mode'] : 'loose',
                    'gross_weight_kg' => filled($header['gross_weight_kg'] ?? null) ? $header['gross_weight_kg'] : null,
                ]],
            ], $lines);

            // Our side of the link. A confirmed order never had anything reserved for an unlinked line, so its quantity goes on
            // backorder quietly (lead decision: no 缺货 exception — the goods are announced) and the putaway event will allocate it.
            $linesById = $orders->flatMap->lines->keyBy('id');
            $byOrder = $orders->keyBy('id');
            foreach ($result['lines'] as $pair) {
                $line = $linesById[$pair['order_line_id']];
                $order = $byOrder[$line->order_id];
                $update = ['asn_line_id' => $pair['asn_line_id']];
                if ($order->operational_status === 'confirmed') {
                    $update['qty_backordered'] = max(0, (int) $line->carton_qty - (int) $line->qty_shipped);
                }
                OrderLine::query()->whereKey($line->id)->update($update);
            }

            foreach ($orders as $order) {
                $linked = $order->lines->whereNull('asn_line_id')->count();
                $this->statuses->note($order->fresh(), $actorId, __('orders.inbound.timeline.asn_generated', ['asn_no' => $result['asn_no'], 'lines' => $linked]));
            }

            return [
                'asn_id' => $result['asn_id'],
                'asn_no' => $result['asn_no'],
                'job_id' => $jobId,
                'job_no' => $master->job->job_no,
                'orders' => $orders->count(),
                'lines' => count($lines),
                'merged' => $merged,
                'cancelled' => $cancelled,
            ];
        });
    }

    private function eligible()
    {
        return Order::query()->where('order_type', 'from_stock')
            ->whereIn('operational_status', self::ELIGIBLE_STATUSES)
            ->whereHas('lines', fn ($query) => $query->whereNull('asn_line_id'));
    }
}
