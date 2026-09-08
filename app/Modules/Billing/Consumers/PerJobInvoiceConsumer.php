<?php

namespace App\Modules\Billing\Consumers;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\MasterData\Models\Client;
use App\Support\Outbox\EventConsumer;
use InvalidArgumentException;

/**
 * ERP_PLAN §7 step 4 / §6.8 #12: for per_job clients the service invoice draft appears when the final transport quote is
 * confirmed (after BillingChargeConsumer has written the freight charges). Monthly clients keep pooling; Finance still
 * reviews and issues the draft. Replay-safe: one open draft per Job.
 */
final class PerJobInvoiceConsumer implements EventConsumer
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function handle(array $envelope): void
    {
        $payload = $envelope['payload'];
        $jobId = (int) ($payload['job_id'] ?? $envelope['job_id'] ?? 0);
        $client = Client::query()->withoutGlobalScopes()->find((int) ($payload['client_id'] ?? 0));
        if ($jobId === 0 || $client === null || $client->invoice_mode !== 'per_job' || ($payload['quote_stage'] ?? 'final') !== 'final') {
            return;
        }
        if (Invoice::query()->withoutGlobalScopes()->where('status', 'draft')->whereHas('lines', fn ($q) => $q->where('job_id', $jobId))->exists()) {
            return;
        }

        try {
            $this->invoices->draftForJob($jobId, 'service');
        } catch (InvalidArgumentException) {
            // nothing unbilled yet (e.g. charges under review) — Finance drafts manually later
        }
    }
}
