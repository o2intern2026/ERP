<?php

namespace App\Modules\Transport\Services;

use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Transport\Mail\DeliveryPodMail;
use App\Modules\Transport\Models\Pod;
use App\Support\Contracts\ExceptionService;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class PodNotificationService
{
    public function __construct(private readonly ExceptionService $exceptions) {}

    public function send(Pod $pod): void
    {
        $pod->loadMissing(['shipment.client', 'podDocument']);
        $shipment = $pod->shipment;
        $email = trim((string) ($shipment->client->contact_email ?: $shipment->client->billing_email));

        if ($email === '') {
            $this->raiseOnce($pod, __('transport.mail.missing_recipient'));

            return;
        }

        try {
            Mail::to($email)->send(new DeliveryPodMail($pod));
        } catch (Throwable $exception) {
            $this->raiseOnce($pod, __('transport.mail.send_failed', ['message' => $exception->getMessage()]));
        }
    }

    private function raiseOnce(Pod $pod, string $message): void
    {
        $shipment = $pod->shipment;
        $exists = ExceptionRecord::query()->withoutGlobalScopes()
            ->where('type', 'integration_failed')
            ->where('source_module', 'transport')
            ->where('source_type', 'shipment')
            ->where('source_id', $shipment->id)
            ->where('status', '!=', 'resolved')
            ->exists();

        if (! $exists) {
            $this->exceptions->raise('integration_failed', 'transport', [
                'job_id' => $shipment->job_id,
                'client_id' => $shipment->client_id,
                'order_id' => $shipment->order_id,
                'source_type' => 'shipment',
                'source_id' => $shipment->id,
                'message' => $message,
            ]);
        }
    }
}
