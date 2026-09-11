<?php

namespace App\Modules\Platform\Services;

use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Job;
use App\Support\Contracts\JobService as JobServiceContract;
use App\Support\Enums;
use App\Support\Tenancy\ClientScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * The only writer of `jobs` (A27, ERP_PLAN §1.6). Statuses are derived from child records; in M1 no child modules
 * exist yet, so the stored values stand. Cost and margin are never returned to a client-role caller.
 */
final class JobService implements JobServiceContract
{
    public function create(int $clientId, string $jobType, array $attributes = []): array
    {
        if (! in_array($jobType, Enums::JOB_TYPES, true)) {
            throw new InvalidArgumentException("Unknown job_type: {$jobType}");
        }

        Client::query()->findOrFail($clientId); // client-scoped: a client user cannot open a Job for another client

        return DB::transaction(function () use ($clientId, $jobType, $attributes): array {
            $job = Job::query()->create([
                'job_no' => $this->nextJobNo(),
                'client_id' => $clientId,
                'job_type' => $jobType,
                'reference' => $attributes['reference'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            return ['job_id' => $job->id, 'job_no' => $job->job_no];
        });
    }

    public function summarize(int $jobId): array
    {
        $job = Job::query()->findOrFail($jobId);
        $estimate = $job->cost_status !== 'confirmed';

        $summary = [
            'job_id' => $job->id,
            'job_no' => $job->job_no,
            'client_id' => $job->client_id,
            'job_type' => $job->job_type,
            'operational_status' => $job->operational_status,
            'revenue_status' => $job->revenue_status,
            'cost_status' => $job->cost_status,
            'estimated_revenue_cents' => $job->estimated_revenue_cents,
            'actual_revenue_cents' => $job->actual_revenue_cents,
            'estimated_cost_cents' => $job->estimated_cost_cents,
            'actual_cost_cents' => $job->actual_cost_cents,
            'margin_cents' => $estimate
                ? $job->estimated_revenue_cents - $job->estimated_cost_cents
                : $job->actual_revenue_cents - $job->actual_cost_cents,
            'margin_is_estimate' => $estimate,
        ];

        // AGENTS.md: cost and margin must never be serialised for client-role users — enforced here, not in views.
        if (ClientScope::isClientRequest()) {
            unset($summary['estimated_cost_cents'], $summary['actual_cost_cents'], $summary['margin_cents'], $summary['margin_is_estimate']);
        }

        return $summary;
    }

    /** JOB-YYYYMMDD-NNNN, sequence per day, safe under concurrent creation (row lock on today's last number). */
    private function nextJobNo(): string
    {
        $prefix = 'JOB-'.now()->format('Ymd').'-';

        $last = Job::query()->withoutGlobalScopes()
            ->where('job_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('job_no')
            ->value('job_no');

        $sequence = $last ? ((int) substr((string) $last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /** CHANGE_REQUESTS #117: the emptied per-order Job after 从订单生成预报单 merged its order into the shipment Job. */
    public function cancelIfEmpty(int $jobId, string $note): bool
    {
        return DB::transaction(function () use ($jobId, $note): bool {
            $job = Job::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($jobId);
            if ($job->operational_status === 'cancelled') {
                return true;
            }
            foreach (['orders', 'asns', 'goods_receipts', 'warehouse_tasks', 'shipments', 'charges', 'invoice_lines', 'documents'] as $table) {
                if (Schema::hasColumn($table, 'job_id') && DB::table($table)->where('job_id', $jobId)->exists()) {
                    return false;
                }
            }
            // Undelivered events still carry this Job in their envelope (CR #119 review): a consumer running later would open a
            // shipment or a reservation under a cancelled Job, so the Job stays open until cron has delivered them.
            if (DB::table('outbox_events')->where('job_id', $jobId)->whereIn('status', ['pending', 'failed'])->exists()) {
                return false;
            }
            $job->update(['operational_status' => 'cancelled', 'notes' => trim(($job->notes ? $job->notes."\n" : '').$note)]);

            return true;
        });
    }

    /**
     * The tables whose rows follow an order into its new Job — the explicit list of contracts/services.md §5 / db-schema.md
     * (each carries both order_id and job_id); carrier_costs follow their shipment, order documents their related_id. Platform is
     * the one place allowed to rewrite job_id on another module's rows, and only here.
     *
     * @var list<string>
     */
    private const ORDER_SCOPED_TABLES = ['shipments', 'customer_quotes', 'exceptions', 'packages', 'outbound_dispatches', 'warehouse_tasks'];

    /**
     * CHANGE_REQUESTS #117 / #119: the order merged into a shipment Job takes its dependent records with it — the outbound
     * shipment Transport opened at order.confirmed (CR #118), its carrier costs, quotes, holds, packages, dispatches, tasks
     * and order documents — otherwise the per-order Job stays non-empty and can never be closed.
     */
    public function moveOrder(int $orderId, int $toJobId): int
    {
        return DB::transaction(function () use ($orderId, $toJobId): int {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first(['id', 'job_id', 'client_id']);
            if ($order === null) {
                throw new InvalidArgumentException("Unknown order: {$orderId}");
            }
            $job = Job::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($toJobId);
            if ((int) $job->client_id !== (int) $order->client_id) {
                throw new InvalidArgumentException("Order {$orderId} and Job {$job->job_no} belong to different clients.");
            }
            if ((int) $order->job_id === $toJobId) {
                return 0;
            }

            $fromJobId = (int) $order->job_id;
            $moved = 0;
            DB::table('orders')->where('id', $orderId)->update(['job_id' => $toJobId]);
            foreach (self::ORDER_SCOPED_TABLES as $table) {
                $moved += DB::table($table)->where('order_id', $orderId)->where('job_id', $fromJobId)->update(['job_id' => $toJobId]);
            }
            $shipmentIds = DB::table('shipments')->where('order_id', $orderId)->pluck('id');
            if ($shipmentIds->isNotEmpty()) {
                $moved += DB::table('carrier_costs')->whereIn('shipment_id', $shipmentIds)->where('job_id', $fromJobId)->update(['job_id' => $toJobId]);
            }
            $moved += DB::table('documents')->where('related_type', 'order')->where('related_id', $orderId)->where('job_id', $fromJobId)->update(['job_id' => $toJobId]);

            return $moved;
        });
    }
}
