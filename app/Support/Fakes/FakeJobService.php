<?php

namespace App\Support\Fakes;

use App\Support\Contracts\JobService;

/** Sequential Jobs with JOB-YYYYMMDD-NNNN numbers; summarize() returns a fresh Job with zero money. */
final class FakeJobService implements JobService
{
    /** @var array<int, array{job_no:string, client_id:int, job_type:string}> */
    private array $jobs = [];

    public function create(int $clientId, string $jobType, array $attributes = []): array
    {
        $id = count($this->jobs) + 1;
        $jobNo = sprintf('JOB-%s-%04d', now()->format('Ymd'), $id);
        $this->jobs[$id] = ['job_no' => $jobNo, 'client_id' => $clientId, 'job_type' => $jobType];

        return ['job_id' => $id, 'job_no' => $jobNo];
    }

    public function summarize(int $jobId): array
    {
        $job = $this->jobs[$jobId] ?? ['job_no' => sprintf('JOB-FAKE-%04d', $jobId), 'client_id' => 1, 'job_type' => 'container'];

        return [
            'job_id' => $jobId,
            'job_no' => $job['job_no'],
            'client_id' => $job['client_id'],
            'job_type' => $job['job_type'],
            'operational_status' => 'open',
            'revenue_status' => 'unbilled',
            'cost_status' => 'estimated',
            'estimated_revenue_cents' => 0,
            'actual_revenue_cents' => 0,
            'estimated_cost_cents' => 0,
            'actual_cost_cents' => 0,
            'margin_cents' => 0,
            'margin_is_estimate' => true,
        ];
    }
}
