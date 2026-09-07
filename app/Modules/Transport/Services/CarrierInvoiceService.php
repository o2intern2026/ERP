<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Models\CarrierCost;
use App\Modules\Transport\Models\CarrierInvoice;
use App\Modules\Transport\Models\Shipment;
use App\Support\Contracts\DocumentService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Imports a carrier CSV statement and compares each tracking number with its locked quote cost. */
final class CarrierInvoiceService
{
    private const REQUIRED_COLUMNS = ['tracking_number', 'billed_cents'];

    public function __construct(
        private readonly DocumentService $documents,
        private readonly CarrierCostService $costs,
    ) {}

    public function import(
        int $carrierId,
        string $invoiceNumber,
        string $periodFrom,
        string $periodTo,
        int $totalCents,
        UploadedFile $statement,
        ?int $uploadedBy = null,
    ): CarrierInvoice {
        $content = $statement->get();
        $rows = $this->parse($content);
        if ($rows === []) {
            throw new DomainException(__('transport.reconciliation.empty_statement'));
        }
        if ($totalCents < 0 || $periodFrom > $periodTo) {
            throw new DomainException(__('transport.reconciliation.invalid_header'));
        }
        if (CarrierInvoice::query()->where('carrier_id', $carrierId)->where('invoice_no', trim($invoiceNumber))->exists()) {
            throw new DomainException(__('transport.reconciliation.duplicate_invoice'));
        }

        $storedPath = 'transport/carrier-invoices/'.Str::uuid().'.csv';
        try {
            return DB::transaction(function () use ($carrierId, $invoiceNumber, $periodFrom, $periodTo, $totalCents, $statement, $content, $rows, $uploadedBy, $storedPath): CarrierInvoice {
                if (! Storage::disk('local')->put($storedPath, $content)) {
                    throw new DomainException(__('transport.reconciliation.storage_failed'));
                }

                $invoice = CarrierInvoice::query()->create([
                    'carrier_id' => $carrierId,
                    'invoice_no' => trim($invoiceNumber),
                    'period_from' => $periodFrom,
                    'period_to' => $periodTo,
                    'total_cents' => $totalCents,
                    'status' => 'received',
                ]);
                $documentId = $this->documents->attach('invoice', 'carrier_invoice', $invoice->id, $storedPath, [
                    'client_visible' => false,
                    'original_name' => $statement->getClientOriginalName(),
                    'mime' => $statement->getClientMimeType() ?: 'text/csv',
                    'size_bytes' => strlen($content),
                    'uploaded_by' => $uploadedBy,
                ]);
                $invoice->update(['document_id' => $documentId]);

                foreach ($rows as $row) {
                    $this->compareLine($invoice, $row);
                }

                $lineTotal = (int) $invoice->lines()->sum('billed_cents');
                $allMatched = $invoice->lines()->where('matched', false)->doesntExist();
                $invoice->update([
                    'status' => $allMatched && $lineTotal === $invoice->total_cents ? 'matched' : 'disputed',
                ]);

                return $invoice->refresh()->load('carrier', 'document', 'lines.shipment');
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPath);

            throw $exception;
        }
    }

    /** @param array{tracking_number:string,billed_cents:int,note:?string} $row */
    private function compareLine(CarrierInvoice $invoice, array $row): void
    {
        $shipments = Shipment::query()
            ->where('carrier_id', $invoice->carrier_id)
            ->where('tracking_number', $row['tracking_number'])
            ->limit(2)
            ->get();
        $shipment = $shipments->count() === 1 ? $shipments->first() : null;
        $cost = $shipment === null
            ? null
            : CarrierCost::query()
                ->where('shipment_id', $shipment->id)
                ->where('carrier_id', $invoice->carrier_id)
                ->first();
        $expected = (int) ($cost?->expected_cost_cents ?? 0);
        $matched = $shipment !== null && $cost !== null && $row['billed_cents'] === $expected;

        $invoice->lines()->create([
            'shipment_id' => $shipment?->id,
            'tracking_number' => $row['tracking_number'],
            'billed_cents' => $row['billed_cents'],
            'expected_cents' => $expected,
            'variance_cents' => $row['billed_cents'] - $expected,
            'matched' => $matched,
            'note' => $row['note'],
        ]);

        if ($cost !== null) {
            $this->costs->confirmActual(
                $cost,
                $row['billed_cents'],
                __('transport.reconciliation.cost_note', ['invoice' => $invoice->invoice_no]),
            );
        }
    }

    /** @return list<array{tracking_number:string,billed_cents:int,note:?string}> */
    private function parse(string $content): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new DomainException(__('transport.reconciliation.invalid_csv'));
        }
        fwrite($stream, $content);
        rewind($stream);
        $header = fgetcsv($stream);
        if ($header === false) {
            fclose($stream);

            return [];
        }

        $columns = [];
        foreach ($header as $index => $name) {
            $columns[strtolower(trim((string) $name, "\xEF\xBB\xBF \t\n\r\0\x0B"))] = $index;
        }
        foreach (self::REQUIRED_COLUMNS as $required) {
            if (! array_key_exists($required, $columns)) {
                fclose($stream);
                throw new DomainException(__('transport.reconciliation.missing_column', ['column' => $required]));
            }
        }

        $grouped = [];
        $line = 1;
        while (($values = fgetcsv($stream)) !== false) {
            $line++;
            $tracking = trim((string) ($values[$columns['tracking_number']] ?? ''));
            if ($tracking === '' && count(array_filter($values, fn ($value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $billed = filter_var($values[$columns['billed_cents']] ?? null, FILTER_VALIDATE_INT);
            if ($tracking === '' || $billed === false || $billed < 0) {
                fclose($stream);
                throw new DomainException(__('transport.reconciliation.invalid_row', ['row' => $line]));
            }

            $note = isset($columns['note']) ? trim((string) ($values[$columns['note']] ?? '')) : '';
            $grouped[$tracking] ??= ['tracking_number' => $tracking, 'billed_cents' => 0, 'notes' => []];
            $grouped[$tracking]['billed_cents'] += $billed;
            if ($note !== '') {
                $grouped[$tracking]['notes'][] = $note;
            }
        }
        fclose($stream);

        return array_values(array_map(fn (array $row): array => [
            'tracking_number' => $row['tracking_number'],
            'billed_cents' => $row['billed_cents'],
            'note' => $row['notes'] === [] ? null : implode('; ', array_unique($row['notes'])),
        ], $grouped));
    }
}
