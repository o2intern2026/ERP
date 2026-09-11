<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Platform\Models\Job;
use App\Support\Contracts\InboundService;
use App\Support\Contracts\JobService;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 从订单生成预报单 (lead decision 2026-09-11, CHANGE_REQUESTS #117): the client's orders are the upstream document. When the goods
 * are announced, staff pick the orders that arrive together and one ASN is opened for them through Warehouse's InboundService —
 * one goods line per order line, linked in both directions — so receiving → putaway allocates the orders on its own (backorders).
 * The orders share one Job afterwards: the first order's Job hosts the ASN, the others are merged into it and their emptied
 * per-order Jobs are cancelled. No warehouse rule changes; the coordinator's manual ASN and 从预报单生成派送订单 stay for goods that
 * arrive before any order exists.
 *
 * CHANGE_REQUESTS #119 adds the mirror image on the ASN page: an existing booked / arrived / receiving ASN imports the client's
 * pending orders as goods lines (attachToAsn) under exactly the same rules — eligibility, Job merge, line payload, link-back.
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
     * The client's orders still waiting for an ASN, oldest first — the pick list of 从订单导入货物行 on the ASN page
     * (contracts/services.md §2 OrderService::awaitingAsn, CHANGE_REQUESTS #119).
     *
     * @return list<array{order_id:int, order_no:string, job_id:int, job_no:string, consignment_mark:?string, deliver_to_name:?string, deliver_to_suburb:?string, deliver_to_state:?string, requested_date:?string, operational_status:string, unlinked_lines:int, total_lines:int, unlinked_cartons:int}>
     */
    public function awaitingAsn(int $clientId): array
    {
        return $this->eligible()->with(['job', 'lines'])->where('client_id', $clientId)->orderBy('id')->get()
            ->map(function (Order $order): array {
                $unlinked = $order->lines->whereNull('asn_line_id');

                return [
                    'order_id' => (int) $order->id,
                    'order_no' => (string) $order->order_no,
                    'job_id' => (int) $order->job_id,
                    'job_no' => (string) $order->job?->job_no,
                    'consignment_mark' => $order->consignment_mark,
                    'deliver_to_name' => $order->deliver_to_name,
                    'deliver_to_suburb' => $order->deliver_to_suburb,
                    'deliver_to_state' => $order->deliver_to_state,
                    'requested_date' => $order->requested_date?->toDateString(),
                    'operational_status' => (string) $order->operational_status,
                    'unlinked_lines' => $unlinked->count(),
                    'total_lines' => $order->lines->count(),
                    'unlinked_cartons' => (int) $unlinked->sum('carton_qty'),
                ];
            })->values()->all();
    }

    /**
     * @param  list<int>  $orderIds
     * @param  array{warehouse_id:int|string, inbound_type:string, expected_date?:?string, notes?:?string, container_no?:?string, container_size?:?string, unpack_mode?:?string, gross_weight_kg?:mixed}  $header
     * @return array{asn_id:int, asn_no:string, job_id:int, job_no:string, orders:int, lines:int, merged:list<string>, cancelled:list<string>}
     */
    public function generate(array $orderIds, array $header, ?int $actorId): array
    {
        return DB::transaction(function () use ($orderIds, $header, $actorId): array {
            $orders = $this->lockOrders($orderIds);
            if ($orders->pluck('client_id')->unique()->count() > 1) {
                throw new RuleViolation('Orders of different clients cannot share an ASN.', 'orders.inbound.errors.mixed_clients');
            }
            $this->guardEligible($orders);

            // One Job for the shipment: the first order's; the others move in, their emptied Jobs are cancelled.
            $master = $orders->first();
            $jobId = (int) $master->job_id;
            $jobNo = (string) $master->job->job_no;
            ['merged' => $merged, 'cancelled' => $cancelled] = $this->mergeIntoJob($orders, $jobId, $jobNo);

            $container = $header['inbound_type'] === 'container' && filled($header['container_no'] ?? null) ? trim((string) $header['container_no']) : null;
            $lines = $this->linePayload($orders, $container);

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

            $this->linkLines($orders, $result, $actorId);

            return [
                'asn_id' => $result['asn_id'],
                'asn_no' => $result['asn_no'],
                'job_id' => $jobId,
                'job_no' => $jobNo,
                'orders' => $orders->count(),
                'lines' => count($lines),
                'merged' => $merged,
                'cancelled' => $cancelled,
            ];
        });
    }

    /**
     * 从订单导入货物行 (CHANGE_REQUESTS #119): the client's pending orders become goods lines of an ASN that already exists. Same
     * rules as generate() — the orders must belong to the ASN's client, be eligible, and end up in the ASN's Job (emptied
     * per-order Jobs are cancelled; a Job that already carries an ASN cannot be merged). Warehouse's InboundService refuses
     * an ASN that is past receiving. The ASN header and its containers are read only; Warehouse writes the asn_lines.
     *
     * @param  list<int>  $orderIds
     * @param  ?string  $containerNo  the header container the lines go onto (Warehouse defaults it on a one-container ASN, requires it on a multi-container one)
     * @return array{orders:int, lines:int, merged:list<string>, cancelled:list<string>, job_no:string}
     */
    public function attachToAsn(int $asnId, array $orderIds, ?int $actorId, ?string $containerNo = null): array
    {
        return DB::transaction(function () use ($asnId, $orderIds, $actorId, $containerNo): array {
            // Read-only look at the ASN header (client, Job, existence) — the same precedent as mergeIntoJob's job_has_asn check
            // (#117); every write to asns / asn_lines / containers stays with Warehouse. Locked for the whole merge + link, in the
            // same order Warehouse takes its locks (asn → orders), so putaway cannot flip the ASN underneath the import.
            $asn = DB::table('asns')->where('id', $asnId)->lockForUpdate()->first(['id', 'client_id', 'job_id']);
            if ($asn === null) {
                throw new InvalidArgumentException("ASN {$asnId} does not exist.");
            }
            $jobId = (int) $asn->job_id;
            $jobNo = (string) Job::query()->findOrFail($jobId)->job_no;

            $orders = $this->lockOrders($orderIds);
            foreach ($orders as $order) {
                if ((int) $order->client_id !== (int) $asn->client_id) {
                    throw new RuleViolation("Order {$order->order_no} does not belong to the ASN's client.", 'orders.inbound.errors.asn_other_client', ['order_no' => $order->order_no]);
                }
            }
            $this->guardEligible($orders);

            ['merged' => $merged, 'cancelled' => $cancelled] = $this->mergeIntoJob($orders, $jobId, $jobNo);

            // Which container a line lands on is Warehouse's rule (contracts/services.md §10): the ASN's only container by
            // default, the caller's choice when it has several — here we only pass the choice through.
            $lines = $this->linePayload($orders, filled($containerNo) ? trim((string) $containerNo) : null);

            $result = $this->inbound->addOrderLinesToAsn($asnId, $lines);

            $this->linkLines($orders, $result, $actorId);

            return [
                'orders' => $orders->count(),
                'lines' => count($lines),
                'merged' => $merged,
                'cancelled' => $cancelled,
                'job_no' => $jobNo,
            ];
        });
    }

    /**
     * Lock the picked orders (with their lines and Job) for the rest of the transaction.
     *
     * @param  list<int>  $orderIds
     * @return Collection<int, Order>
     */
    private function lockOrders(array $orderIds): Collection
    {
        $orders = Order::query()->with(['lines', 'job'])->whereKey($orderIds)->lockForUpdate()->orderBy('id')->get();
        if ($orders->isEmpty()) {
            throw new RuleViolation('No orders selected.', 'orders.inbound.errors.none_selected');
        }

        return $orders;
    }

    /**
     * Only from_stock orders that have not started picking, with at least one goods line still without an ASN line.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function guardEligible(Collection $orders): void
    {
        foreach ($orders as $order) {
            if ($order->order_type !== 'from_stock' || ! in_array($order->operational_status, self::ELIGIBLE_STATUSES, true)) {
                throw new RuleViolation("Order {$order->order_no} is not eligible for an ASN.", 'orders.inbound.errors.order_not_eligible', ['order_no' => $order->order_no]);
            }
            if ($order->lines->isEmpty() || $order->lines->every(fn (OrderLine $l) => $l->asn_line_id !== null)) {
                throw new RuleViolation("Order {$order->order_no} already has its goods on an ASN.", 'orders.inbound.errors.already_linked', ['order_no' => $order->order_no]);
            }
        }
    }

    /**
     * Move every order that sits in another Job into the ASN's Job; cancel the per-order Jobs left empty. A Job that already
     * carries an ASN is never merged away.
     *
     * @param  Collection<int, Order>  $orders
     * @return array{merged:list<string>, cancelled:list<string>}
     */
    private function mergeIntoJob(Collection $orders, int $jobId, string $jobNo): array
    {
        $merged = [];
        $cancelled = [];
        foreach ($orders as $order) {
            if ((int) $order->job_id === $jobId) {
                continue;
            }
            if (DB::table('asns')->where('job_id', $order->job_id)->exists()) {
                throw new RuleViolation("The Job of {$order->order_no} already carries an ASN.", 'orders.inbound.errors.job_has_asn', ['order_no' => $order->order_no, 'job_no' => $order->job->job_no]);
            }
            // An undelivered event (order.confirmed that dispatch-now could not deliver, a failed retry) still names the old Job
            // in its envelope; Warehouse and Transport would open the reservation / shipment under a Job we are about to empty
            // and cancel. Cron delivers within a minute — the staff simply retry.
            if (DB::table('outbox_events')->where('job_id', $order->job_id)->whereIn('status', ['pending', 'failed'])->exists()) {
                throw new RuleViolation("Order {$order->order_no} still has undelivered events under its Job.", 'orders.inbound.errors.events_pending', ['order_no' => $order->order_no]);
            }
            $oldJobId = (int) $order->job_id;
            $oldJobNo = $order->job->job_no;
            $this->jobs->moveOrder($order->id, $jobId); // the order's shipment / quotes / holds follow it, so the old Job is really empty
            $merged[] = $order->order_no;
            if ($this->jobs->cancelIfEmpty($oldJobId, __('orders.inbound.job_cancelled_note', ['job_no' => $jobNo, 'order_no' => $order->order_no]))) {
                $cancelled[] = $oldJobNo;
            }
        }

        return ['merged' => $merged, 'cancelled' => $cancelled];
    }

    /**
     * One asn_lines payload per goods line that has no ASN line yet: consignee, FBA reference and mark from the order,
     * description / cartons / package / weight / dims from the line (contracts/services.md §10).
     *
     * @param  Collection<int, Order>  $orders
     * @return list<array<string, mixed>>
     */
    private function linePayload(Collection $orders, ?string $containerNo): array
    {
        $lines = [];
        foreach ($orders as $order) {
            foreach ($order->lines->whereNull('asn_line_id') as $line) {
                $lines[] = [
                    'order_line_id' => $line->id,
                    'container_no' => $containerNo,
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

        return $lines;
    }

    /**
     * Our side of the link. A confirmed order never had anything reserved for an unlinked line, so its quantity goes on
     * backorder quietly (lead decision: no 缺货 exception — the goods are announced) and the putaway event will allocate it.
     * One timeline note per order names the ASN and how many of its lines were linked.
     *
     * @param  Collection<int, Order>  $orders
     * @param  array{asn_no:string, lines:list<array{order_line_id:int, asn_line_id:int}>}  $result
     */
    private function linkLines(Collection $orders, array $result, ?int $actorId): void
    {
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
            $linked = $order->lines->whereNull('asn_line_id')->count(); // in-memory lines still carry the pre-link null
            $this->statuses->note($order->fresh(), $actorId, __('orders.inbound.timeline.asn_generated', ['asn_no' => $result['asn_no'], 'lines' => $linked]));
        }
    }

    /** @return Builder<Order> */
    private function eligible(): Builder
    {
        return Order::query()->where('order_type', 'from_stock')
            ->whereIn('operational_status', self::ELIGIBLE_STATUSES)
            ->whereHas('lines', fn ($query) => $query->whereNull('asn_line_id'));
    }
}
