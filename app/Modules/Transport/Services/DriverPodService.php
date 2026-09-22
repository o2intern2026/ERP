<?php

namespace App\Modules\Transport\Services;

use App\Models\User;
use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Transport\Events\DeliveryFailed;
use App\Modules\Transport\Events\DeliveryPodCaptured;
use App\Modules\Transport\Models\DeliveryRun;
use App\Modules\Transport\Models\Pod;
use App\Modules\Transport\Models\RunStop;
use App\Modules\Transport\Models\Shipment;
use App\Support\Contracts\DocumentService;
use App\Support\Contracts\ExceptionService;
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
        private readonly ShipmentProgressService $progress,
        private readonly PodNotificationService $notifications,
        private readonly ExceptionService $exceptions,
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
            $pod = DB::transaction(function () use ($stop, $driver, $recipientName, $signature, $photos, &$storedPaths): Pod {
                [$lockedStop, $shipment, $run] = $this->lockDriverStop($stop, $driver);
                $this->assertStopOpen($lockedStop, $shipment);

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

                $photoDataUris = [];
                $photoDocumentIds = $this->storePhotos($shipment, $driver, $photos, 'delivery-photo', $storedPaths, $photoDataUris);

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

                $lockedStop->update([
                    'arrived_at' => $lockedStop->arrived_at ?? $deliveredAt,
                    'status' => 'delivered',
                ]);
                $this->progress->advance($shipment, 'delivered', $deliveredAt);
                $this->finishRun($run);

                $this->outbox->publish(new DeliveryPodCaptured([
                    'shipment_id' => $shipment->id,
                    'shipment_no' => $shipment->shipment_no,
                    'job_id' => $shipment->job_id,
                    'client_id' => $shipment->client_id,
                    'order_id' => $shipment->order_id,
                    'asn_id' => $shipment->asn_id, // inbound collection (#124): Warehouse marks the 预报单 arrived on this POD
                    'shipment_type' => $shipment->shipment_type,
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

        $this->notifications->send($pod);

        return $pod;
    }

    /**
     * CHANGE_REQUESTS #135 (audit TMS-09): a failed attempt carries an optional note (required for 其他 — "other" with nothing
     * said means a phone call) and optional photos (the closed gate) stored exactly as POD photos; the note goes into the
     * `delivery_failed` exception message, the `delivery.failed` payload stays as contracted.
     *
     * @param  list<UploadedFile>  $photos
     */
    public function fail(RunStop $stop, User $driver, string $reason, ?string $note = null, array $photos = []): Pod
    {
        if (! in_array($reason, self::FAILURE_REASONS, true)) {
            throw new DomainException(__('transport.driver.invalid_failure_reason'));
        }
        $note = trim((string) $note);
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($stop, $driver, $reason, $note, $photos, &$storedPaths): Pod {
                [$lockedStop, $shipment, $run] = $this->lockDriverStop($stop, $driver);
                $this->assertStopOpen($lockedStop, $shipment);
                if ($reason === 'other' && $note === '') {
                    throw new DomainException(__('transport.driver.failure_note_required'));
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
                    'failure_note' => $note === '' ? null : $note,
                    'photo_document_ids' => $this->storePhotos($shipment, $driver, $photos, 'failure-photo', $storedPaths),
                    'captured_by' => $driver->id,
                ]);

                $lockedStop->update(['status' => 'failed']);
                $this->progress->advance($shipment, 'failed', $failedAt);
                $this->finishRun($run);
                $this->raiseDeliveryFailure($shipment, $reason, $note, $driver->id);

                $this->outbox->publish(new DeliveryFailed([
                    'shipment_id' => $shipment->id,
                    'shipment_no' => $shipment->shipment_no,
                    'job_id' => $shipment->job_id,
                    'client_id' => $shipment->client_id,
                    'order_id' => $shipment->order_id,
                    'asn_id' => $shipment->asn_id,
                    'shipment_type' => $shipment->shipment_type,
                    'failed_at' => $failedAt->toIso8601String(),
                    'failure_reason' => $reason,
                    'attempt_no' => $attemptNo,
                    'reported_by_type' => 'driver',
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

    /**
     * CHANGE_REQUESTS #135 (audit TMS-09): only a pending / arrived stop takes a delivery or a failure. A failed stop is closed —
     * a second failure would publish attempt 2 for the same attempt, a delivery after it would leave the shipment 配送失败 with a
     * delivered POD; the office re-attempts through 重新派送 (a new shipment, a new stop).
     */
    private function assertStopOpen(RunStop $stop, Shipment $shipment): void
    {
        if (Pod::query()->where('shipment_id', $shipment->id)->whereNotNull('delivered_at')->exists() || $stop->status === 'delivered') {
            throw new DomainException(__('transport.driver.already_delivered'));
        }
        if ($stop->status === 'failed') {
            $failure = Pod::query()->where('shipment_id', $shipment->id)->whereNotNull('failure_reason')->latest('id')->first();
            throw new DomainException(__('transport.driver.already_failed', [
                'reason' => $failure === null ? '—' : __('transport.driver.failure_reasons.'.$failure->failure_reason),
                'time' => $failure?->created_at?->format('Y-m-d H:i') ?? '—',
            ]));
        }
        if (! in_array($stop->status, ['pending', 'arrived'], true)) {
            throw new DomainException(__('transport.driver.stop_unavailable'));
        }
    }

    /**
     * Stores photos under the shipment's POD folder and attaches each as a client-visible `photo` document — the one path for
     * delivery photos and failure photos (#135). $photoDataUris receives the data URIs the POD PDF embeds.
     *
     * @param  list<UploadedFile>  $photos
     * @param  list<string>  $storedPaths
     * @param  list<string>  $photoDataUris
     * @return list<int>
     */
    private function storePhotos(Shipment $shipment, User $driver, array $photos, string $kind, array &$storedPaths, array &$photoDataUris = []): array
    {
        $photoDocumentIds = [];
        foreach (array_values($photos) as $index => $photo) {
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
                'original_name' => $shipment->shipment_no.'-'.$kind.'-'.($index + 1).".{$extension}",
                'mime' => $mime,
                'size_bytes' => strlen($content),
                'uploaded_by' => $driver->id,
            ]);
            $photoDataUris[] = 'data:'.$mime.';base64,'.base64_encode($content);
        }

        return $photoDocumentIds;
    }

    /** @return array{RunStop, Shipment, DeliveryRun} */
    private function lockDriverStop(RunStop $stop, User $driver): array
    {
        $lockedStop = RunStop::query()->lockForUpdate()->findOrFail($stop->id);
        $run = DeliveryRun::query()->lockForUpdate()->findOrFail($lockedStop->delivery_run_id);
        $shipment = Shipment::query()->lockForUpdate()->findOrFail($lockedStop->shipment_id);

        if (! User::query()->whereKey($driver->id)->where('is_active', true)->exists()
            || ! $driver->hasRole('transport_operator')
            || $run->driver_id !== $driver->id
            || $run->run_date->gt(today()) // CHANGE_REQUESTS #133: on or before today — a run that spilled past midnight is still finished
            || ! in_array($run->status, ['planned', 'dispatched'], true)) {
            throw new DomainException(__('transport.driver.stop_unavailable'));
        }

        return [$lockedStop, $shipment, $run];
    }

    private function finishRun(DeliveryRun $run): void
    {
        $hasOpenStops = RunStop::query()
            ->where('delivery_run_id', $run->id)
            ->whereNotIn('status', ['delivered', 'failed'])
            ->exists();
        $run->status = $hasOpenStops ? 'dispatched' : 'completed';
        $run->save();
    }

    private function raiseDeliveryFailure(Shipment $shipment, string $reason, string $note, int $driverId): void
    {
        $exists = ExceptionRecord::query()->withoutGlobalScopes()
            ->where('type', 'delivery_failed')
            ->where('source_module', 'transport')
            ->where('source_type', 'shipment')
            ->where('source_id', $shipment->id)
            ->where('status', '!=', 'resolved')
            ->exists();

        if (! $exists) {
            $this->exceptions->raise('delivery_failed', 'transport', [
                'job_id' => $shipment->job_id,
                'client_id' => $shipment->client_id,
                'order_id' => $shipment->order_id,
                'source_type' => 'shipment',
                'source_id' => $shipment->id,
                'message' => __('transport.exceptions.driver_failed', [
                    'shipment' => $shipment->shipment_no,
                    'reason' => __('transport.driver.failure_reasons.'.$reason),
                ]).($note === '' ? '' : __('transport.exceptions.driver_failed_note', ['note' => $note])), // #135: the driver's note settles the dispute

                'created_by' => $driverId,
            ]);
        }
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
