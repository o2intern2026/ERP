<?php

namespace App\Modules\Transport\Services;

use App\Models\User;
use App\Modules\Transport\Events\DeliveryFailed;
use App\Modules\Transport\Events\DeliveryPodCaptured;
use App\Modules\Transport\Models\DeliveryRun;
use App\Modules\Transport\Models\Pod;
use App\Modules\Transport\Models\RunStop;
use App\Modules\Transport\Models\Shipment;
use App\Support\Contracts\DocumentService;
use App\Support\Outbox\OutboxPublisher;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class DriverPodService
{
    public const FAILURE_REASONS = [
        'recipient_unavailable',
        'address_issue',
        'access_blocked',
        'delivery_refused',
        'goods_damaged',
        'other',
    ];

    public function __construct(
        private readonly DocumentService $documents,
        private readonly OutboxPublisher $outbox,
        private readonly PodPdf $pdf,
    ) {}

    /** @param list<UploadedFile> $photos */
    public function deliver(
        RunStop $stop,
        User $driver,
        string $recipientName,
        string $signatureData,
        array $photos,
    ): Pod {
        $signature = $this->decodeSignature($signatureData);
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($stop, $driver, $recipientName, $signature, $photos, &$storedPaths): Pod {
                [$lockedStop, $shipment] = $this->lockDriverStop($stop, $driver);

                if (Pod::query()->where('shipment_id', $shipment->id)->whereNotNull('delivered_at')->exists()) {
                    throw new DomainException(__('transport.driver.already_delivered'));
                }

                $deliveredAt = now();
                $signaturePath = "transport/shipments/{$shipment->id}/pod/".Str::uuid().'-signature.png';
                $this->store($signaturePath, $signature, $storedPaths);
                $signatureDocumentId = $this->documents->attach('photo', 'shipment', $shipment->id, $signaturePath, [
                    'job_id' => $shipment->job_id,
                    'client_id' => $shipment->client_id,
                    'client_visible' => true,
                    'original_name' => $shipment->shipment_no.'-signature.png',
                    'mime' => 'image/png',
                    'size_bytes' => strlen($signature),
                    'uploaded_by' => $driver->id,
                ]);

                $photoDocumentIds = [];
                $photoDataUris = [];
                foreach ($photos as $index => $photo) {
                    $content = $photo->get();
                    $mime = $this->photoMime($photo);
                    $extension = match ($mime) {
                        'image/png' => 'png',
                        'image/webp' => 'webp',
                        default => 'jpg',
                    };
                    $photoPath = "transport/shipments/{$shipment->id}/pod/".Str::uuid()."-photo.{$extension}";
                    $this->store($photoPath, $content, $storedPaths);
                    $photoDocumentIds[] = $this->documents->attach('photo', 'shipment', $shipment->id, $photoPath, [
                        'job_id' => $shipment->job_id,
                        'client_id' => $shipment->client_id,
                        'client_visible' => true,
                        'original_name' => $shipment->shipment_no.'-delivery-photo-'.($index + 1).".{$extension}",
                        'mime' => $mime,
                        'size_bytes' => strlen($content),
                        'uploaded_by' => $driver->id,
                    ]);
                    $photoDataUris[] = 'data:'.$mime.';base64,'.base64_encode($content);
                }

                $pdfContent = $this->pdf->render(
                    $shipment,
                    $lockedStop,
                    trim($recipientName),
                    $deliveredAt->toIso8601String(),
                    'data:image/png;base64,'.base64_encode($signature),
                    $photoDataUris,
                )->output();
                $pdfPath = "transport/shipments/{$shipment->id}/pod/{$shipment->shipment_no}-pod.pdf";
                $this->store($pdfPath, $pdfContent, $storedPaths);
                $podDocumentId = $this->documents->attach('pod', 'shipment', $shipment->id, $pdfPath, [
                    'job_id' => $shipment->job_id,
                    'client_id' => $shipment->client_id,
                    'client_visible' => true,
                    'original_name' => $shipment->shipment_no.'-pod.pdf',
                    'mime' => 'application/pdf',
                    'size_bytes' => strlen($pdfContent),
                    'uploaded_by' => $driver->id,
                ]);

                $pod = Pod::query()->create([
                    'shipment_id' => $shipment->id,
                    'delivered_at' => $deliveredAt,
                    'recipient_name' => trim($recipientName),
                    'signature_document_id' => $signatureDocumentId,
                    'photo_document_ids' => $photoDocumentIds,
                    'pod_document_id' => $podDocumentId,
                    'failure_reason' => null,
                    'captured_by' => $driver->id,
                ]);

                $this->outbox->publish(new DeliveryPodCaptured([
                    'shipment_id' => $shipment->id,
                    'shipment_no' => $shipment->shipment_no,
                    'job_id' => $shipment->job_id,
                    'client_id' => $shipment->client_id,
                    'order_id' => $shipment->order_id,
                    'fulfilment_id' => $shipment->fulfilment_id,
                    'delivered_at' => $deliveredAt->toIso8601String(),
                    'recipient_name' => trim($recipientName),
                    'pod_document_id' => $podDocumentId,
                    'photo_document_ids' => $photoDocumentIds,
                    'captured_by_type' => 'driver',
                    'captured_by' => $driver->id,
                ], jobId: $shipment->job_id, clientId: $shipment->client_id, correlationId: $shipment->shipment_no));

                return $pod->refresh();
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $storedPath) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    public function fail(RunStop $stop, User $driver, string $reason): Pod
    {
        if (! in_array($reason, self::FAILURE_REASONS, true)) {
            throw new DomainException(__('transport.driver.invalid_failure_reason'));
        }

        return DB::transaction(function () use ($stop, $driver, $reason): Pod {
            [, $shipment] = $this->lockDriverStop($stop, $driver);

            if (Pod::query()->where('shipment_id', $shipment->id)->whereNotNull('delivered_at')->exists()) {
                throw new DomainException(__('transport.driver.already_delivered'));
            }

            $attemptNo = Pod::query()
                ->where('shipment_id', $shipment->id)
                ->whereNotNull('failure_reason')
                ->lockForUpdate()
                ->count() + 1;
            $failedAt = now();
            $pod = Pod::query()->create([
                'shipment_id' => $shipment->id,
                'failure_reason' => $reason,
                'captured_by' => $driver->id,
            ]);

            $this->outbox->publish(new DeliveryFailed([
                'shipment_id' => $shipment->id,
                'shipment_no' => $shipment->shipment_no,
                'job_id' => $shipment->job_id,
                'client_id' => $shipment->client_id,
                'order_id' => $shipment->order_id,
                'failed_at' => $failedAt->toIso8601String(),
                'failure_reason' => $reason,
                'attempt_no' => $attemptNo,
                'reported_by_type' => 'driver',
            ], jobId: $shipment->job_id, clientId: $shipment->client_id, correlationId: $shipment->shipment_no));

            return $pod->refresh();
        });
    }

    /** @return array{RunStop, Shipment} */
    private function lockDriverStop(RunStop $stop, User $driver): array
    {
        $lockedStop = RunStop::query()->lockForUpdate()->findOrFail($stop->id);
        $run = DeliveryRun::query()->lockForUpdate()->findOrFail($lockedStop->delivery_run_id);
        $shipment = Shipment::query()->lockForUpdate()->findOrFail($lockedStop->shipment_id);

        if (! User::query()->whereKey($driver->id)->where('is_active', true)->exists()
            || ! $driver->hasRole('transport_operator')
            || $run->driver_id !== $driver->id
            || ! $run->run_date->isSameDay(today())
            || ! in_array($run->status, ['planned', 'dispatched'], true)) {
            throw new DomainException(__('transport.driver.stop_unavailable'));
        }

        return [$lockedStop, $shipment];
    }

    private function decodeSignature(string $signatureData): string
    {
        if (! preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=\r\n]+)$/', $signatureData, $matches)) {
            throw new DomainException(__('transport.driver.invalid_signature'));
        }

        $signature = base64_decode($matches[1], true);
        if ($signature === false || ! str_starts_with($signature, "\x89PNG\r\n\x1a\n")) {
            throw new DomainException(__('transport.driver.invalid_signature'));
        }

        return $signature;
    }

    /** @param list<string> $storedPaths */
    private function store(string $path, string $content, array &$storedPaths): void
    {
        if (! Storage::disk('local')->put($path, $content)) {
            throw new DomainException(__('transport.driver.storage_failed'));
        }

        $storedPaths[] = $path;
    }

    private function photoMime(UploadedFile $photo): string
    {
        $mime = $photo->getMimeType();

        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? $mime : 'image/jpeg';
    }
}
