<?php

namespace App\Modules\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * CHANGE_REQUESTS #157: remove a manual TEST ROUND from a trial database — every business record hanging off the given Jobs
 * (orders, fulfilments, ASNs, receipts, stock units and their ledger, tasks, waves, packages, dispatches, shipments, runs left empty,
 * quotes, charges, invoices with their lines / payments / credit notes, exceptions, documents and their files, outbox events) plus the
 * given order imports. Dry run unless --force; one transaction, so a foreign-key refusal leaves the database untouched.
 *
 * This is a TRIAL-SERVER tool for re-testing: it deletes what the business rules (ERP_PLAN §0.2) say is never deleted in production.
 * Never run it against production data; take a dump first (deploy/README.md).
 */
final class PurgeTestRoundCommand extends Command
{
    protected $signature = 'erp:purge-test-round {--jobs= : Comma-separated job ids of the test round} {--imports= : Comma-separated order_import ids} {--force : Actually delete (default is a dry run)}';

    protected $description = 'Trial server only: delete a manual test round (the given Jobs and imports with everything hanging off them); dry run unless --force';

    public function handle(): int
    {
        $jobs = $this->ids('jobs');
        $imports = $this->ids('imports');
        if ($jobs === [] && $imports === []) {
            $this->error('Give --jobs=… and/or --imports=….');

            return self::FAILURE;
        }
        if (app()->environment('production') && ! $this->option('force')) {
            $this->warn('Environment is production: this is a dry run; --force deletes for real.');
        }

        $counts = [];
        $force = (bool) $this->option('force');
        $plan = function () use ($jobs, $imports, &$counts, $force): void { // a closure by reference: an arrow fn would copy $counts
            $this->purge($jobs, $imports, $counts, $force);
        };
        if ($this->option('force')) {
            DB::transaction($plan);
        } else {
            DB::beginTransaction();
            try {
                $plan();
            } finally {
                DB::rollBack();
            }
        }
        ksort($counts);
        foreach ($counts as $table => $count) {
            $this->line(sprintf('%-28s %6d', $table, $count));
        }
        $this->info(($this->option('force') ? 'Deleted' : 'DRY RUN — would delete').' '.array_sum($counts).' rows for jobs ['.implode(',', $jobs).'] and imports ['.implode(',', $imports).']'.($this->option('force') ? '.' : '. Add --force to delete.'));

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function ids(string $option): array
    {
        return array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $this->option($option))))));
    }

    /**
     * Child rows first, parents last; every table guarded by hasTable so an older schema still purges what it has.
     *
     * @param  list<int>  $jobs
     * @param  list<int>  $imports
     * @param  array<string, int>  $counts
     */
    private function purge(array $jobs, array $imports, array &$counts, bool $deleteFiles): void
    {
        $del = function (string $table, $query) use (&$counts): void {
            if (! Schema::hasTable($table)) {
                return;
            }
            $counts[$table] = ($counts[$table] ?? 0) + $query->delete();
        };
        $has = fn (string $table, string $column): bool => Schema::hasTable($table) && in_array($column, Schema::getColumnListing($table), true);

        $orders = DB::table('orders')->whereIn('job_id', $jobs)->pluck('id')->all();
        $fulfilments = DB::table('fulfilments')->whereIn('order_id', $orders)->pluck('id')->all();
        $asns = DB::table('asns')->whereIn('job_id', $jobs)->pluck('id')->all();
        $asnLines = DB::table('asn_lines')->whereIn('asn_id', $asns)->pluck('id')->all();
        $units = DB::table('stock_units')->whereIn('job_id', $jobs)->pluck('id')->all();
        $shipments = DB::table('shipments')->whereIn('job_id', $jobs)->pluck('id')->all();
        $tasks = DB::table('warehouse_tasks')->whereIn('job_id', $jobs)->pluck('id')->all();
        $invoices = DB::table('invoice_jobs')->whereIn('job_id', $jobs)->pluck('invoice_id')->unique()->values()->all();
        $charges = DB::table('charges')->whereIn('job_id', $jobs)->pluck('id')->all();
        $receipts = DB::table('goods_receipts')->whereIn('job_id', $jobs)->pluck('id')->all();
        $events = DB::table('outbox_events')->whereIn('job_id', $jobs)->pluck('event_id')->all();
        $docs = DB::table('documents')->where(fn ($q) => $q->whereIn('job_id', $jobs)
            ->orWhere(fn ($w) => $w->where('related_type', 'order_import')->whereIn('related_id', $imports))
            ->orWhere(fn ($w) => $w->where('related_type', 'asn')->whereIn('related_id', $asns))
            ->orWhere(fn ($w) => $w->where('related_type', 'invoice')->whereIn('related_id', $invoices))
            ->orWhere(fn ($w) => $w->where('related_type', 'shipment')->whereIn('related_id', $shipments))
            ->orWhere(fn ($w) => $w->where('related_type', 'order')->whereIn('related_id', $orders)))->get(['id', 'storage_path']);

        // Transport (shipments.selected_quote_id RESTRICTs the quotes and the quotes RESTRICT the shipment: unlink, quotes, then shipments)
        if ($has('shipments', 'selected_quote_id')) {
            DB::table('shipments')->whereIn('id', $shipments)->update(['selected_quote_id' => null]);
        }
        foreach (['tracking_events', 'pods', 'run_stops', 'carrier_invoice_lines', 'shipment_extra_charges', 'transport_quotes'] as $t) {
            if ($has($t, 'shipment_id')) {
                $del($t, DB::table($t)->whereIn('shipment_id', $shipments));
            }
        }
        if ($has('carrier_costs', 'shipment_id')) {
            $del('carrier_costs', DB::table('carrier_costs')->where(fn ($q) => $q->whereIn('shipment_id', $shipments)->orWhereIn('job_id', $jobs)));
        }
        $del('outbound_dispatches', DB::table('outbound_dispatches')->where(fn ($q) => $q->whereIn('fulfilment_id', $fulfilments)->orWhereIn('shipment_id', $shipments)));
        $del('packages', DB::table('packages')->where(fn ($q) => $q->whereIn('job_id', $jobs)->orWhereIn('fulfilment_id', $fulfilments)));
        $del('shipments', DB::table('shipments')->whereIn('id', $shipments));
        if ($has('delivery_runs', 'id') && $has('run_stops', 'delivery_run_id')) {
            $emptyRuns = DB::table('delivery_runs')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('run_stops')->whereColumn('run_stops.delivery_run_id', 'delivery_runs.id'))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('shipments')->whereColumn('shipments.delivery_run_id', 'delivery_runs.id'))->pluck('id')->all();
            $del('delivery_runs', DB::table('delivery_runs')->whereIn('id', $emptyRuns));
        }
        // Billing
        if ($has('credit_notes', 'invoice_id')) {
            $creditNotes = DB::table('credit_notes')->whereIn('invoice_id', $invoices)->pluck('id')->all();
            if ($has('credit_note_lines', 'credit_note_id')) {
                $del('credit_note_lines', DB::table('credit_note_lines')->where(fn ($q) => $q->whereIn('credit_note_id', $creditNotes)->when($has('credit_note_lines', 'charge_id'), fn ($w) => $w->orWhereIn('charge_id', $charges))));
            }
            $del('credit_notes', DB::table('credit_notes')->whereIn('id', $creditNotes));
        }
        $del('invoice_lines', DB::table('invoice_lines')->where(fn ($q) => $q->whereIn('invoice_id', $invoices)->orWhereIn('charge_id', $charges)->orWhereIn('order_id', $orders)));
        $del('payments', DB::table('payments')->whereIn('invoice_id', $invoices));
        $del('invoice_jobs', DB::table('invoice_jobs')->whereIn('invoice_id', $invoices));
        $del('invoices', DB::table('invoices')->whereIn('id', $invoices));
        $del('charges', DB::table('charges')->whereIn('id', $charges));
        $del('customer_quotes', DB::table('customer_quotes')->where(fn ($q) => $q->whereIn('job_id', $jobs)->orWhereIn('order_id', $orders)));
        $del('exceptions', DB::table('exceptions')->where(fn ($q) => $q->whereIn('job_id', $jobs)->orWhereIn('order_id', $orders)));
        // Warehouse tasks, waves
        $del('scan_records', DB::table('scan_records')->where(fn ($q) => $q->whereIn('task_id', $tasks)->orWhereIn('stock_unit_id', $units)));
        $del('warehouse_task_lines', DB::table('warehouse_task_lines')->where(fn ($q) => $q->whereIn('task_id', $tasks)->orWhereIn('stock_unit_id', $units)->orWhereIn('asn_line_id', $asnLines)));
        $del('warehouse_tasks', DB::table('warehouse_tasks')->where(fn ($q) => $q->whereIn('id', $tasks)->orWhereIn('order_id', $orders)->orWhereIn('asn_id', $asns)));
        $del('waves', DB::table('waves')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('warehouse_tasks')->whereColumn('warehouse_tasks.wave_id', 'waves.id')));
        // Stock (return receipts of the round go first: their lines point at the units)
        if ($has('return_receipts', 'job_id')) {
            $returns = DB::table('return_receipts')->whereIn('job_id', $jobs)->pluck('id')->all();
            if ($has('return_receipt_lines', 'return_receipt_id')) {
                $del('return_receipt_lines', DB::table('return_receipt_lines')->where(fn ($q) => $q->whereIn('return_receipt_id', $returns)->orWhereIn('stock_unit_id', $units)));
            }
            $del('return_receipts', DB::table('return_receipts')->whereIn('id', $returns));
        }
        foreach (['stock_snapshots', 'stocktake_lines', 'return_receipt_lines'] as $t) {
            if ($has($t, 'stock_unit_id')) {
                $del($t, DB::table($t)->where(fn ($q) => $q->whereIn('stock_unit_id', $units)
                    ->when($has($t, 'asn_line_id'), fn ($w) => $w->orWhereIn('asn_line_id', $asnLines))
                    ->when($has($t, 'job_id'), fn ($w) => $w->orWhereIn('job_id', $jobs))));
            }
        }
        $del('stock_reservations', DB::table('stock_reservations')->where(fn ($q) => $q->whereIn('order_id', $orders)->orWhereIn('stock_unit_id', $units)));
        $del('stock_ledger', DB::table('stock_ledger')->where(fn ($q) => $q->whereIn('stock_unit_id', $units)->orWhereIn('from_stock_unit_id', $units)->orWhereIn('to_stock_unit_id', $units)));
        $del('stock_units', DB::table('stock_units')->whereIn('id', $units));
        $del('goods_receipt_lines', DB::table('goods_receipt_lines')->where(fn ($q) => $q->whereIn('goods_receipt_id', $receipts)->orWhereIn('asn_line_id', $asnLines)));
        $del('goods_receipts', DB::table('goods_receipts')->whereIn('id', $receipts));
        // Orders
        $del('fulfilment_lines', DB::table('fulfilment_lines')->whereIn('fulfilment_id', $fulfilments));
        $del('fulfilments', DB::table('fulfilments')->whereIn('id', $fulfilments));
        foreach (['order_events', 'declared_packages', 'order_lines', 'order_api_idempotency_keys'] as $t) {
            if ($has($t, 'order_id')) {
                $del($t, DB::table($t)->whereIn('order_id', $orders));
            }
        }
        $del('orders', DB::table('orders')->whereIn('id', $orders));
        // ASN, containers
        foreach (['portal_asn_submissions', 'asn_imports', 'containers'] as $t) {
            if ($has($t, 'asn_id')) {
                $del($t, DB::table($t)->whereIn('asn_id', $asns));
            }
        }
        $del('asn_lines', DB::table('asn_lines')->whereIn('id', $asnLines));
        $del('asns', DB::table('asns')->whereIn('id', $asns));
        if ($has('physical_containers', 'id') && $has('containers', 'physical_container_id')) {
            $del('physical_containers', DB::table('physical_containers')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('containers')->whereColumn('containers.physical_container_id', 'physical_containers.id')));
        }
        // Imports, documents (+ files), events, jobs
        $del('order_imports', DB::table('order_imports')->whereIn('id', $imports));
        foreach ($docs as $doc) {
            if ($deleteFiles && filled($doc->storage_path) && Storage::disk('local')->exists($doc->storage_path)) {
                Storage::disk('local')->delete($doc->storage_path);
                $counts['files'] = ($counts['files'] ?? 0) + 1;
            }
        }
        $del('documents', DB::table('documents')->whereIn('id', $docs->pluck('id')));
        if ($has('consumed_events', 'event_id')) {
            $del('consumed_events', DB::table('consumed_events')->whereIn('event_id', $events));
        }
        $del('outbox_events', DB::table('outbox_events')->whereIn('job_id', $jobs));
        $del('jobs', DB::table('jobs')->whereIn('id', $jobs));
    }
}
