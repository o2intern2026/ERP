<?php

namespace App\Modules\Transport\Services;

use App\Models\User;
use App\Modules\Transport\Events\DeliveryPodCaptured;
use App\Modules\Transport\Models\Pod;
use App\Modules\Transport\Models\Shipment;
use App\Support\Contracts\DocumentService;
use App\Support\Outbox\OutboxPublisher;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class CarrierPodService
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly OutboxPublisher $outbox,
        private readonly ShipmentProgressService $progress,
        private readonly PodNotificationService $notifications,
    ) {}

    public function capture(Shipment $shipment, User $uploadedBy, string $recipientName, UploadedFile $file): Pod
    {
        $content = $file->get();
        if (! str_starts_with($content, '%PDF-')) {
            throw new DomainException(__('transport.carrier_pod.invalid_pdf'));
        }

        $storedPath = null;
        try {
            $pod = DB::transaction(function () use ($shipment, $uploadedBy, $recipientName, $content, &$storedPath): Pod {
                $locked = Shipment::query()->with('selectedQuote')->lockForUpdate()->findOrFail($shipment->id);
                if ($locked->selectedQuote === null || $locked->selectedQuote->source === 'own_fleet') {
                    throw new DomainException(__('transport.carrier_pod.third_party_only'));
                }
                if (Pod::query()->where('shipment_id', $locked->id)->whereNotNull('delivered_at')->exists()) {
                    throw new DomainException(__('transport.driver.already_delivered'));
                }

                $deliveredAt = now();
                $filename = $locked->shipment_no.'-carrier-pod.pdf';
                $storedPath = "transport/shipments/{$locked->id}/pod/{$filename}";
                if (! Storage::disk('local')->put($storedPath, $content)) {
                    throw new DomainException(__('transport.driver.storage_failed'));
                }

                $documentId = $this->documents->attach('pod', 'shipment', $locked->id, $storedPath, [
                    'job_id' => $locked->job_id,
                    'client_id' => $locked->client_id,
                    'client_visible' => true,
                    'original_name' => $filename,
                    'mime' => 'application/pdf',
                    'size_bytes' => strlen($content),
                    'uploaded_by' => $uploadedBy->id,
                ]);
                $pod = Pod::query()->create([
                    'shipment_id' => $locked->id,
                    'delivered_at' => $deliveredAt,
                    'recipient_name' => trim($recipientName),
                    'signature_document_id' => null,
                    'photo_document_ids' => [],
                    'pod_document_id' => $documentId,
                    'failure_reason' => null,
                    'captured_by' => null,
                ]);

                $this->progress->advance($locked, 'delivered', $deliveredAt);
                $this->outbox->publish(new DeliveryPodCaptured([
                    'shipment_id' => $locked->id,
                    'shipment_no' => $locked->shipment_no,
                    'job_id' => $locked->job_id,
                    'client_id' => $locked->client_id,
                    'order_id' => $locked->order_id,
                    'fulfilment_id' => $locked->fulfilment_id,
                    'delivered_at' => $deliveredAt->toIso8601String(),
                    'recipient_name' => trim($recipientName),
                    'pod_document_id' => $documentId,
                    'photo_document_ids' => [],
                    'captured_by_type' => 'carrier_api',
                    'captured_by' => null,
                ], jobId: $locked->job_id, clientId: $locked->client_id, correlationId: $locked->shipment_no));

                return $pod->refresh();
            });
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }

        $this->notifications->send($pod);

        return $pod;
    }
}
