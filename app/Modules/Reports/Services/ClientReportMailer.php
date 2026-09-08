<?php

namespace App\Modules\Reports\Services;

use App\Modules\MasterData\Models\Client;
use App\Modules\Reports\Mail\ClientReportMail;
use App\Modules\Reports\Models\ReportDelivery;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * A22 (PLT-5): render each active client's A21 client view for a period and mail it to `clients.billing_email` (fallback
 * `contact_email`). Clients without an address are skipped (recorded once), a period already sent is never mailed twice
 * unless forced, and a mailer failure is recorded instead of aborting the run. Cost / margin never appear: the mail is
 * built from ReportService::client(), which does not even select them.
 */
final class ClientReportMailer
{
    public const FREQUENCIES = ['weekly', 'monthly'];

    public function __construct(private readonly ReportService $reports) {}

    /** @return array{sent:int, skipped:int, no_address:int, already_sent:int, failed:int} */
    public function send(string $frequency, ReportPeriod $period, bool $force = false, ?int $onlyClientId = null): array
    {
        if (! in_array($frequency, self::FREQUENCIES, true)) {
            throw new \InvalidArgumentException("Unknown report frequency: {$frequency}");
        }
        $stats = ['sent' => 0, 'skipped' => 0, 'no_address' => 0, 'already_sent' => 0, 'failed' => 0];

        $clients = Client::query()->withoutGlobalScopes()->where('status', 'active')
            ->when($onlyClientId !== null, fn ($q) => $q->whereKey($onlyClientId))
            ->orderBy('id')->get();

        foreach ($clients as $client) {
            $email = trim((string) ($client->billing_email ?: $client->contact_email));
            $log = ReportDelivery::query()->withoutGlobalScopes()->where('client_id', $client->id)->where('frequency', $frequency)->whereDate('period_start', $period->from);

            if ($email === '') {
                if (! $log->clone()->where('status', 'skipped')->exists()) {
                    $this->record($client, $frequency, $period, null, 'skipped', 'no_address');
                }
                $stats['skipped']++;
                $stats['no_address']++;

                continue;
            }
            if (! $force && $log->clone()->where('status', 'sent')->exists()) {
                $stats['skipped']++;
                $stats['already_sent']++;

                continue;
            }

            try {
                Mail::to($email)->send($this->mailable($client, $frequency, $period));
                $this->record($client, $frequency, $period, $email, 'sent');
                $stats['sent']++;
            } catch (Throwable $e) {
                $this->record($client, $frequency, $period, $email, 'failed', mb_substr($e->getMessage(), 0, 500));
                $stats['failed']++;
            }
        }

        return $stats;
    }

    public function mailable(Client $client, string $frequency, ReportPeriod $period): ClientReportMail
    {
        $report = $this->reports->client($client->id, $period);
        $columns = collect(ReportService::TABLES)->mapWithKeys(fn ($t) => [$t => $this->reports->columns($t, true)])->all();
        $totals = collect(ReportService::TABLES)->mapWithKeys(fn ($t) => [$t => $this->reports->totals($t, $report[$t], true)])->all();

        return new ClientReportMail($client, $period, $frequency, $report, $columns, $totals);
    }

    private function record(Client $client, string $frequency, ReportPeriod $period, ?string $email, string $status, ?string $error = null): void
    {
        ReportDelivery::query()->create([
            'client_id' => $client->id, 'frequency' => $frequency, 'period_start' => $period->from->toDateString(), 'period_end' => $period->to->toDateString(),
            'email' => $email, 'status' => $status, 'error' => $error, 'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }
}
