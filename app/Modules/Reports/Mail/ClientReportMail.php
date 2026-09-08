<?php

namespace App\Modules\Reports\Mail;

use App\Modules\MasterData\Models\Client;
use App\Modules\Reports\Services\ReportCsv;
use App\Modules\Reports\Services\ReportPeriod;
use App\Modules\Reports\Services\ReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A22 (PLT-5): one client's A21 client-view report for a period — customer figures only, one CSV attachment per table. */
class ClientReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, list<array<string, mixed>>>  $report  ReportService::client(...)
     * @param  array<string, array<string, string>>  $columns  table => column => type (client view)
     * @param  array<string, array<string, mixed>|null>  $totals
     */
    public function __construct(
        public Client $client,
        public ReportPeriod $period,
        public string $frequency,
        public array $report,
        public array $columns,
        public array $totals,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('reports.mail.subject', [
            'client' => $this->client->name,
            'frequency' => __('reports.mail.frequencies.'.$this->frequency),
            'from' => $this->period->from->toDateString(),
            'to' => $this->period->to->toDateString(),
        ]));
    }

    public function content(): Content
    {
        return new Content(view: 'reports::mail.client-report', with: ['tables' => ReportService::TABLES]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $csv = app(ReportCsv::class);

        return array_map(fn (string $table) => Attachment::fromData(
            fn () => $csv->render($this->columns[$table], $this->report[$table], $this->totals[$table] ?? null),
            $csv->filename($table, $this->period, $this->client->code),
        )->withMime('text/csv'), ReportService::TABLES);
    }
}
