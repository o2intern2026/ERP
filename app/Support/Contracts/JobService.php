<?php

namespace App\Support\Contracts;

/**
 * Provided by Platform (seat C, M1/A27). contracts/services.md §5.
 * The only way to create Jobs; every business record carries job_id (ERP_PLAN §0.2 rule 1).
 */
interface JobService
{
    /**
     * @param  string  $jobType  contracts/enums.md jobs.job_type
     * @param  array{reference?:string, notes?:string}  $attributes
     * @return array{job_id:int, job_no:string}
     */
    public function create(int $clientId, string $jobType, array $attributes = []): array;

    /**
     * Derived statuses and cached money for the Job workbench (ERP_PLAN §1.6).
     *
     * @return array{job_id:int, job_no:string, client_id:int, job_type:string, operational_status:string, revenue_status:string, cost_status:string, estimated_revenue_cents:int, actual_revenue_cents:int, estimated_cost_cents:int, actual_cost_cents:int, margin_cents:int, margin_is_estimate:bool}
     */
    public function summarize(int $jobId): array;

    /**
     * Cancel a Job no business record refers to any more (orders, ASNs, shipments, charges, documents, …) — the per-order
     * loose Job left behind when its order is merged into a shipment Job (从订单生成预报单, CHANGE_REQUESTS #117). Leaves the Job
     * untouched and returns false while something still points at it.
     */
    public function cancelIfEmpty(int $jobId, string $note): bool;
}
