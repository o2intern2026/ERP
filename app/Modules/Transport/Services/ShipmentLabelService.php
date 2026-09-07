<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Models\Shipment;
use App\Support\Contracts\CarrierAdapter;
use App\Support\Contracts\DocumentService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ShipmentLabelService
{
    /** @var array<string, CarrierAdapter> */
    private array $adapters = [];

    /** @param iterable<CarrierAdapter> $adapters */
    public function __construct(
        private readonly PackageManifest $packages,
        private readonly OwnFleetLabelPdf $ownFleetPdf,
        private readonly DocumentService $documents,
        iterable $adapters,
    ) {
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->source()] = $adapter;
        }
    }

    /** @return array{content:string, filename:string, document_type:string} */
    public function document(Shipment $shipment): array
    {
        $storedPath = null;

        try {
            return DB::transaction(function () use ($shipment, &$storedPath): array {
                $lockedShipment = Shipment::query()
                    ->with(['carrier', 'selectedQuote.carrier', 'waybillDocument'])
                    ->lockForUpdate()
                    ->findOrFail($shipment->id);

                $quote = $lockedShipment->selectedQuote;
                if ($quote === null || $quote->quote_stage !== 'final' || $quote->status !== 'selected') {
                    throw new DomainException(__('transport.labels.no_selected_quote'));
                }

                if ($lockedShipment->waybillDocument !== null) {
                    return $this->storedDocument($lockedShipment, $quote->source);
                }

                if ($quote->source === 'own_fleet') {
                    $receiver = $this->receiver($quote->raw_response ?? []);
                    $packages = $this->packages->forShipment($lockedShipment);
                    $carrierName = $lockedShipment->carrier?->name ?? $quote->carrier->name;
                    $filename = $lockedShipment->shipment_no.'-labels.pdf';
                    $content = $this->ownFleetPdf
                        ->render($lockedShipment, $packages, $receiver, $carrierName)
                        ->output();
                    $documentType = 'label';
                } else {
                    $adapter = $this->adapters[$quote->source] ?? null;
                    if (trim((string) $lockedShipment->booking_ref) === ''
                        || $adapter === null
                        || ! ($adapter->capabilities()['label'] ?? false)) {
                        throw new DomainException(__('transport.labels.waybill_unavailable'));
                    }

                    $content = $adapter->label($lockedShipment->booking_ref);
                    if ($content === null || ! str_starts_with($content, '%PDF-')) {
                        throw new DomainException(__('transport.labels.waybill_unavailable'));
                    }

                    $filename = $lockedShipment->shipment_no.'-waybill.pdf';
                    $documentType = 'waybill';
                }

                $storedPath = "transport/shipments/{$lockedShipment->id}/{$filename}";
                if (! Storage::disk('local')->put($storedPath, $content)) {
                    throw new DomainException(__('transport.labels.storage_failed'));
                }

                $documentId = $this->documents->attach(
                    $documentType,
                    'shipment',
                    $lockedShipment->id,
                    $storedPath,
                    [
                        'job_id' => $lockedShipment->job_id,
                        'client_id' => $lockedShipment->client_id,
                        'client_visible' => true,
                        'original_name' => $filename,
                        'mime' => 'application/pdf',
                        'size_bytes' => strlen($content),
                    ],
                );
                $lockedShipment->update(['waybill_document_id' => $documentId]);

                return [
                    'content' => $content,
                    'filename' => $filename,
                    'document_type' => $documentType,
                ];
            });
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    /** @return array{content:string, filename:string, document_type:string} */
    private function storedDocument(Shipment $shipment, string $source): array
    {
        $document = $shipment->waybillDocument;
        $expectedType = $source === 'own_fleet' ? 'label' : 'waybill';
        if ($document->related_type !== 'shipment'
            || $document->related_id !== $shipment->id
            || $document->type !== $expectedType
            || ! Storage::disk('local')->exists($document->storage_path)) {
            throw new DomainException(__('transport.labels.stored_document_missing'));
        }

        $content = Storage::disk('local')->get($document->storage_path);
        if (! str_starts_with($content, '%PDF-')) {
            throw new DomainException(__('transport.labels.stored_document_missing'));
        }

        return [
            'content' => $content,
            'filename' => $document->original_name ?: $shipment->shipment_no.'-shipping-document.pdf',
            'document_type' => $document->type,
        ];
    }

    /** @param array<string, mixed> $rawResponse */
    private function receiver(array $rawResponse): array
    {
        $receiver = data_get($rawResponse, '_quote_request.receiver');
        if (! is_array($receiver)) {
            throw new DomainException(__('transport.labels.receiver_missing'));
        }

        foreach (['name', 'address', 'suburb', 'state', 'postcode'] as $field) {
            if (trim((string) ($receiver[$field] ?? '')) === '') {
                throw new DomainException(__('transport.labels.receiver_missing'));
            }
        }

        return $receiver;
    }
}
