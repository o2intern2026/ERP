<?php

namespace App\Modules\Orders\Mail;

use App\Modules\MasterData\Models\Client;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** CHANGE_REQUESTS #145: the result of one automated list (inbox folder) — the same Chinese text the `.result.txt` holds. */
final class ImportResultMail extends Mailable
{
    /** @param array<string, mixed>|null $summary */
    public function __construct(public readonly Client $client, public readonly ?array $summary, public readonly string $text) {}

    public function envelope(): Envelope
    {
        $status = (string) ($this->summary['status'] ?? 'failed');

        return new Envelope(subject: __('orders.imports.auto.mail_subject', [
            'file' => (string) ($this->summary['file_name'] ?? ''),
            'status' => __('orders.imports.auto.statuses.'.(in_array($status, ['imported', 'pending'], true) ? $status : 'failed')),
        ]));
    }

    public function content(): Content
    {
        return new Content(htmlString: '<pre style="font-family:inherit;white-space:pre-wrap">'.e($this->text).'</pre>');
    }
}
