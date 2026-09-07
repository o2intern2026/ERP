<?php

namespace App\Modules\Platform\Services;

use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Job;
use App\Support\Contracts\JobService as JobServiceContract;
use App\Support\Enums;
use App\Support\Tenancy\ClientScope;
use Illuminate\Support\Facades\DB;
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
}
