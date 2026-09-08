<?php

namespace App\Modules\Reports\Services;

use App\Modules\Orders\OrderEnums;
use App\Support\Tenancy\ClientScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A21 (PLT-4, ERP_PLAN §2.5 #5): the boss view (every client, revenue / cost / margin) and the client view (one client,
 * customer figures only). Read-only grouped SQL over the modules' tables — no models, no N+1, cross-module tables read as
 * plain `DB::table()`. Multi-tenancy: the client view always carries an explicit `client_id` predicate (the global Eloquent
 * scope does not apply to the query builder) and a client-role request can never ask for another client (#52 / #53).
 *
 * Cost and margin columns are never even selected for the client view (AGENTS.md: "never queried or serialised").
 */
final class ReportService
{
    public const TABLES = ['orders_by_status', 'orders_by_client', 'on_time', 'inbound', 'outbound', 'financials', 'exceptions'];

    /** table => [column => type]; types drive ReportFormat (text | int | money | percent | datetime). */
    public const COLUMNS = [
        'orders_by_status' => ['status' => 'text', 'orders' => 'int', 'cartons' => 'int'],
        'orders_by_client' => ['client' => 'text', 'orders' => 'int', 'cartons' => 'int', 'delivered' => 'int', 'cancelled' => 'int'],
        'on_time' => ['client' => 'text', 'delivered' => 'int', 'on_time' => 'int', 'late' => 'int', 'on_time_rate' => 'percent', 'overdue_open' => 'int'],
        'inbound' => ['client' => 'text', 'asns' => 'int', 'cartons' => 'int', 'pallets' => 'int'],
        'outbound' => ['client' => 'text', 'packed_batches' => 'int', 'packages' => 'int', 'dispatched_batches' => 'int', 'dispatched_pallets' => 'int', 'dispatched_packages' => 'int'],
        'financials' => ['client' => 'text', 'jobs' => 'int', 'estimated_revenue_cents' => 'money', 'actual_revenue_cents' => 'money', 'estimated_cost_cents' => 'money', 'actual_cost_cents' => 'money', 'margin_cents' => 'money', 'estimated_jobs' => 'int'],
        'exceptions' => ['type' => 'text', 'open' => 'int', 'oldest' => 'datetime'],
    ];

    /** Columns that never appear in the client view (cost, margin and the cost-status count). */
    public const INTERNAL_COLUMNS = ['estimated_cost_cents', 'actual_cost_cents', 'margin_cents', 'estimated_jobs'];

    /** Exception types a client may see about its own goods; the rest (missing_rate, billing_hold, integration_failed, holds…) is internal. */
    public const CLIENT_EXCEPTION_TYPES = ['discrepancy', 'pick_short', 'delivery_failed'];

    /** @return array<string, list<array<string, mixed>>> */
    public function boss(ReportPeriod $period): array
    {
        return $this->build($period, null);
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function client(int $clientId, ReportPeriod $period): array
    {
        // Defence in depth: a client-role request may only ever report on its own client.
        abort_if(ClientScope::isClientRequest() && ClientScope::currentClientId() !== $clientId, 403);

        return $this->build($period, $clientId);
    }

    /** @return array<string, string> column => type */
    public function columns(string $table, bool $clientView): array
    {
        $columns = self::COLUMNS[$table] ?? abort(404);

        return $clientView ? array_diff_key($columns, array_flip(self::INTERNAL_COLUMNS)) : $columns;
    }

    /**
     * A totals row for numeric tables (null when the table has no rows or nothing to sum); rates are recomputed, not summed.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    public function totals(string $table, array $rows, bool $clientView): ?array
    {
        if ($rows === [] || $table === 'exceptions') {
            return null;
        }
        $columns = $this->columns($table, $clientView);
        $totals = [];
        foreach ($columns as $key => $type) {
            $totals[$key] = match ($type) {
                'int', 'money' => array_sum(array_map(fn ($r) => (int) ($r[$key] ?? 0), $rows)),
                default => null,
            };
        }
        $totals[array_key_first($columns)] = __('reports.total');
        if ($table === 'on_time') {
            $totals['on_time_rate'] = ($totals['delivered'] ?? 0) > 0 ? (float) ($totals['on_time'] / $totals['delivered']) : null;
        }

        return $totals;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function build(ReportPeriod $period, ?int $clientId): array
    {
        return [
            'orders_by_status' => $this->ordersByStatus($period, $clientId),
            'orders_by_client' => $this->ordersByClient($period, $clientId),
            'on_time' => $this->onTime($period, $clientId),
            'inbound' => $this->inbound($period, $clientId),
            'outbound' => $this->outbound($period, $clientId),
            'financials' => $this->financials($period, $clientId),
            'exceptions' => $this->exceptions($clientId),
        ];
    }

    /** Orders entered in the period, per operational status (every status listed, in the contract order). */
    private function ordersByStatus(ReportPeriod $period, ?int $clientId): array
    {
        $counts = DB::table('orders')->leftJoin('order_lines', 'order_lines.order_id', '=', 'orders.id')
            ->tap(fn (Builder $q) => $this->inPeriod($q, 'orders.created_at', $period))
            ->when($clientId !== null, fn (Builder $q) => $q->where('orders.client_id', $clientId))
            ->groupBy('orders.operational_status')
            ->selectRaw('orders.operational_status as status, count(distinct orders.id) as orders, coalesce(sum(order_lines.carton_qty), 0) as cartons')
            ->get()->keyBy('status');

        return array_map(fn (string $status) => [
            'status' => __('orders.statuses.operational.'.$status),
            'orders' => (int) ($counts[$status]->orders ?? 0),
            'cartons' => (int) ($counts[$status]->cartons ?? 0),
        ], OrderEnums::OPERATIONAL_STATUSES);
    }

    /** Orders entered in the period, per client. */
    private function ordersByClient(ReportPeriod $period, ?int $clientId): array
    {
        return DB::table('orders')->join('clients', 'clients.id', '=', 'orders.client_id')->leftJoin('order_lines', 'order_lines.order_id', '=', 'orders.id')
            ->tap(fn (Builder $q) => $this->inPeriod($q, 'orders.created_at', $period))
            ->when($clientId !== null, fn (Builder $q) => $q->where('orders.client_id', $clientId))
            ->groupBy('orders.client_id', 'clients.name')
            ->orderBy('clients.name')
            ->selectRaw("orders.client_id, clients.name as client, count(distinct orders.id) as orders, coalesce(sum(order_lines.carton_qty), 0) as cartons,
                count(distinct case when orders.operational_status = 'delivered' then orders.id end) as delivered,
                count(distinct case when orders.operational_status = 'cancelled' then orders.id end) as cancelled")
            ->get()
            ->map(fn ($r) => ['client_id' => (int) $r->client_id, 'client' => $r->client, 'orders' => (int) $r->orders, 'cartons' => (int) $r->cartons, 'delivered' => (int) $r->delivered, 'cancelled' => (int) $r->cancelled])
            ->values()->all();
    }

    /**
     * On-time rate: orders whose `delivered` transition (order_events, Orders' own timeline) happened in the period, on time when the
     * delivery day is on or before `requested_date`; plus the orders currently open past their requested date.
     */
    private function onTime(ReportPeriod $period, ?int $clientId): array
    {
        $delivered = DB::table('order_events as e')->join('orders as o', 'o.id', '=', 'e.order_id')->join('clients as c', 'c.id', '=', 'o.client_id')
            ->where('e.dimension', 'operational')->where('e.to_status', 'delivered')
            ->where(fn (Builder $w) => $w->whereNull('e.from_status')->orWhereColumn('e.from_status', '!=', 'e.to_status'))
            ->tap(fn (Builder $q) => $this->inPeriod($q, 'e.created_at', $period))
            ->when($clientId !== null, fn (Builder $q) => $q->where('o.client_id', $clientId))
            ->groupBy('o.client_id', 'c.name')
            ->selectRaw('o.client_id, c.name as client, count(distinct o.id) as delivered, count(distinct case when date(e.created_at) <= o.requested_date then o.id end) as on_time')
            ->get()->keyBy('client_id');

        $overdue = DB::table('orders')->join('clients', 'clients.id', '=', 'orders.client_id')
            ->whereNotIn('orders.operational_status', ['delivered', 'returned', 'cancelled'])
            ->whereDate('orders.requested_date', '<', today())
            ->when($clientId !== null, fn (Builder $q) => $q->where('orders.client_id', $clientId))
            ->groupBy('orders.client_id', 'clients.name')
            ->selectRaw('orders.client_id, clients.name as client, count(*) as overdue_open')
            ->get()->keyBy('client_id');

        return $delivered->keys()->merge($overdue->keys())->unique()
            ->map(function ($id) use ($delivered, $overdue): array {
                $d = (int) ($delivered[$id]->delivered ?? 0);
                $on = (int) ($delivered[$id]->on_time ?? 0);

                return ['client_id' => (int) $id, 'client' => $delivered[$id]->client ?? $overdue[$id]->client, 'delivered' => $d, 'on_time' => $on, 'late' => $d - $on, 'on_time_rate' => $d > 0 ? (float) ($on / $d) : null, 'overdue_open' => (int) ($overdue[$id]->overdue_open ?? 0)];
            })
            ->sortBy('client')->values()->all();
    }

    /** Inbound volume: ASNs that arrived in the period (booking date when never marked arrived), their received cartons and pallet units. */
    private function inbound(ReportPeriod $period, ?int $clientId): array
    {
        $asns = DB::table('asns as a')->join('clients as c', 'c.id', '=', 'a.client_id')->leftJoin('asn_lines as l', 'l.asn_id', '=', 'a.id')
            ->whereRaw('coalesce(a.arrived_at, a.created_at) >= ?', [$period->from])->whereRaw('coalesce(a.arrived_at, a.created_at) < ?', [$period->toExclusive()])
            ->when($clientId !== null, fn (Builder $q) => $q->where('a.client_id', $clientId))
            ->groupBy('a.client_id', 'c.name')
            ->selectRaw('a.client_id, c.name as client, count(distinct a.id) as asns, coalesce(sum(l.received_cartons), 0) as cartons')
            ->get()->keyBy('client_id');

        $pallets = DB::table('stock_units as su')->join('asn_lines as l', 'l.id', '=', 'su.asn_line_id')->join('asns as a', 'a.id', '=', 'l.asn_id')
            ->where('su.unit_type', 'pallet')
            ->whereRaw('coalesce(a.arrived_at, a.created_at) >= ?', [$period->from])->whereRaw('coalesce(a.arrived_at, a.created_at) < ?', [$period->toExclusive()])
            ->when($clientId !== null, fn (Builder $q) => $q->where('a.client_id', $clientId))
            ->groupBy('a.client_id')
            ->selectRaw('a.client_id, count(*) as pallets')
            ->get()->keyBy('client_id');

        return $asns->map(fn ($r) => ['client_id' => (int) $r->client_id, 'client' => $r->client, 'asns' => (int) $r->asns, 'cartons' => (int) $r->cartons, 'pallets' => (int) ($pallets[$r->client_id]->pallets ?? 0)])
            ->sortBy('client')->values()->all();
    }

    /** Outbound volume: fulfilment batches packed (Warehouse `packages`) and dispatched (`outbound_dispatches`) in the period. */
    private function outbound(ReportPeriod $period, ?int $clientId): array
    {
        $packed = DB::table('packages as p')->join('clients as c', 'c.id', '=', 'p.client_id')
            ->tap(fn (Builder $q) => $this->inPeriod($q, 'p.created_at', $period))
            ->when($clientId !== null, fn (Builder $q) => $q->where('p.client_id', $clientId))
            ->groupBy('p.client_id', 'c.name')
            ->selectRaw('p.client_id, c.name as client, count(distinct p.fulfilment_id) as packed_batches, count(*) as packages')
            ->get()->keyBy('client_id');

        $dispatched = DB::table('outbound_dispatches as d')->join('clients as c', 'c.id', '=', 'd.client_id')
            ->tap(fn (Builder $q) => $this->inPeriod($q, 'd.dispatched_at', $period))
            ->when($clientId !== null, fn (Builder $q) => $q->where('d.client_id', $clientId))
            ->groupBy('d.client_id', 'c.name')
            ->selectRaw('d.client_id, c.name as client, count(*) as dispatched_batches, coalesce(sum(d.pallet_count), 0) as dispatched_pallets, coalesce(sum(d.package_count), 0) as dispatched_packages')
            ->get()->keyBy('client_id');

        return $packed->keys()->merge($dispatched->keys())->unique()
            ->map(fn ($id) => [
                'client_id' => (int) $id, 'client' => $packed[$id]->client ?? $dispatched[$id]->client,
                'packed_batches' => (int) ($packed[$id]->packed_batches ?? 0), 'packages' => (int) ($packed[$id]->packages ?? 0),
                'dispatched_batches' => (int) ($dispatched[$id]->dispatched_batches ?? 0), 'dispatched_pallets' => (int) ($dispatched[$id]->dispatched_pallets ?? 0), 'dispatched_packages' => (int) ($dispatched[$id]->dispatched_packages ?? 0),
            ])
            ->sortBy('client')->values()->all();
    }

    /**
     * Revenue / cost / margin from the cached Job figures (Jobs opened in the period). Margin follows JobService::summarize:
     * actual − actual once `cost_status = confirmed`, estimated − estimated before. The client view selects revenue only.
     */
    private function financials(ReportPeriod $period, ?int $clientId): array
    {
        $select = 'j.client_id, c.name as client, count(*) as jobs, coalesce(sum(j.estimated_revenue_cents), 0) as estimated_revenue_cents, coalesce(sum(j.actual_revenue_cents), 0) as actual_revenue_cents';
        if ($clientId === null) {
            $select .= ", coalesce(sum(j.estimated_cost_cents), 0) as estimated_cost_cents, coalesce(sum(j.actual_cost_cents), 0) as actual_cost_cents,
                coalesce(sum(case when j.cost_status = 'confirmed' then j.actual_revenue_cents - j.actual_cost_cents else j.estimated_revenue_cents - j.estimated_cost_cents end), 0) as margin_cents,
                sum(case when j.cost_status <> 'confirmed' then 1 else 0 end) as estimated_jobs";
        }

        return DB::table('jobs as j')->join('clients as c', 'c.id', '=', 'j.client_id')
            ->tap(fn (Builder $q) => $this->inPeriod($q, 'j.created_at', $period))
            ->when($clientId !== null, fn (Builder $q) => $q->where('j.client_id', $clientId))
            ->groupBy('j.client_id', 'c.name')
            ->orderBy('c.name')
            ->selectRaw($select)
            ->get()
            ->map(fn ($r) => array_map(fn ($v) => is_numeric($v) && ! is_string($v) ? $v : (is_numeric($v) ? (int) $v : $v), (array) $r))
            ->values()->all();
    }

    /** Open exceptions (not resolved) by type — as of now, not filtered by period. Clients see only the types about their goods. */
    private function exceptions(?int $clientId): array
    {
        return DB::table('exceptions')->where('status', '!=', 'resolved')
            ->when($clientId !== null, fn (Builder $q) => $q->where('client_id', $clientId)->whereIn('type', self::CLIENT_EXCEPTION_TYPES))
            ->groupBy('type', 'hold_type')
            ->orderByDesc('open')
            ->selectRaw('type, hold_type, count(*) as open, min(created_at) as oldest')
            ->get()
            ->map(fn ($r) => [
                'type' => __('reports.exception_types.'.$r->type).($r->type === 'hold' && $r->hold_type ? ' · '.__('orders.holds.types.'.$r->hold_type) : ''),
                'open' => (int) $r->open,
                'oldest' => $r->oldest,
            ])->values()->all();
    }

    private function inPeriod(Builder $query, string $column, ReportPeriod $period): Builder
    {
        return $query->where($column, '>=', $period->from)->where($column, '<', $period->toExclusive());
    }
}
