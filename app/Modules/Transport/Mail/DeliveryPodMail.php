<?php

namespace App\Modules\Transport\Mail;

use App\Modules\Transport\Models\Pod;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DeliveryPodMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Pod $pod) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('transport.mail.pod_subject', ['shipment' => $this->pod->shipment->shipment_no]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'transport::mail.pod-delivered');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $document = $this->pod->podDocument;

        return [
            Attachment::fromStorageDisk('local', $document->storage_path)
                ->as($document->original_name ?: $this->pod->shipment->shipment_no.'-pod.pdf')
                ->withMime($document->mime ?: 'application/pdf'),
        ];
    }
}
