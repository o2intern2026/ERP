<?php

namespace App\Modules\Transport\Services;

use App\Models\User;
use App\Modules\Transport\Events\DeliveryPodCaptured;
use App\Modules\Transport\Models\Pod;
use App\Modules\Transport\Models\Shipment;
use App\Support\Contracts\DocumentService;
use App\Support\Outbox\OutboxPublisher;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class CarrierPodService
{
    /** CHANGE_REQUESTS #135 (audit TMS-12): carriers send PDFs or phone photos — both are archived as the POD document. */
    private const ACCEPTED = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(
        private readonly DocumentService $documents,
        private readonly OutboxPublisher $outbox,
        private readonly ShipmentProgressService $progress,
        private readonly PodNotificationService $notifications,
    ) {}

    /**
     * @param  ?CarbonInterface  $deliveredAt  the 实际签收时间 the carrier reported (CHANGE_REQUESTS #135, audit TMS-12) — not the upload
     *                                         moment; null = now. Never in the future, never before the shipment existed.
     */
    public function capture(Shipment $shipment, User $uploadedBy, string $recipientName, UploadedFile $file, ?CarbonInterface $deliveredAt = null): Pod
    {
        $content = $file->get();
        $mime = self::detectMime($content);
        if ($mime === null) {
            throw new DomainException(__('transport.carrier_pod.invalid_file'));
        }
        $deliveredAt = $deliveredAt === null ? now()->toImmutable() : CarbonImmutable::instance($deliveredAt);
        if ($deliveredAt->isFuture()) {
            throw new DomainException(__('transport.carrier_pod.delivered_in_future'));
        }

        $storedPath = null;
        try {
            $pod = DB::transaction(function () use ($shipment, $uploadedBy, $recipientName, $content, $mime, $deliveredAt, &$storedPath): Pod {
                $locked = Shipment::query()->with('selectedQuote')->lockForUpdate()->findOrFail($shipment->id);
                if ($locked->selectedQuote === null || $locked->selectedQuote->source === 'own_fleet') {
                    throw new DomainException(__('transport.carrier_pod.third_party_only'));
                }
                if (Pod::query()->where('shipment_id', $locked->id)->whereNotNull('delivered_at')->exists()) {
                    throw new DomainException(__('transport.driver.already_delivered'));
                }
                if ($locked->created_at !== null && $deliveredAt->lt($locked->created_at)) {
                    throw new DomainException(__('transport.carrier_pod.delivered_before_shipment', ['time' => $locked->created_at->format('Y-m-d H:i')]));
                }

                $filename = $locked->shipment_no.'-carrier-pod.'.self::ACCEPTED[$mime];
                $storedPath = "transport/shipments/{$locked->id}/pod/{$filename}";
                if (! Storage::disk('local')->put($storedPath, $content)) {
                    throw new DomainException(__('transport.driver.storage_failed'));
                }

                $documentId = $this->documents->attach('pod', 'shipment', $locked->id, $storedPath, [
                    'job_id' => $locked->job_id,
                    'client_id' => $locked->client_id,
                    'client_visible' => true,
                    'original_name' => $filename,
                    'mime' => $mime,
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
                    'asn_id' => $locked->asn_id, // inbound collection (#124)
                    'shipment_type' => $locked->shipment_type,
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

    /** The document type by content, never by the client's file name: `%PDF-` header, JPEG SOI or PNG signature; anything else is refused. */
    private static function detectMime(string $content): ?string
    {
        if (str_starts_with($content, '%PDF-')) {
            return 'application/pdf';
        }
        if (str_starts_with($content, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($content, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }

        return null;
    }
}
