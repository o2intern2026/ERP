<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\GoodsReceipt;
use App\Modules\Warehouse\Models\GoodsReceiptLine;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Contracts\DocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * 入库单 (goods receipt) — tester feedback round 3 item 2, lead decisions 2026-09-08 (CHANGE_REQUESTS #90–#92).
 * ASN = 预报单 (pre-advice); 入库单 = the document of one receiving batch. receipt_no = {asn_no}-R{batch_no}; every batch
 * rolls up to its ASN. ReceivingService opens the batch and snapshots each received line; the operator ends the batch with
 * complete() (totals, PDF stored + attached as a `goods_receipt` document, asns.receiving_completed_at when every line is in).
 * receiveUnplanned() is the one-screen walk-in receipt (无预报收货): unplanned ASN + lines + units + completed batch.
 */
final class GoodsReceiptService
{
    public function __construct(private readonly DocumentService $documents, private readonly AsnService $asns) {}

    /**
     * The ASN's current open batch, or the next one. The ASN row is locked so two operators cannot open two batches at once.
     *
     * @param  array{delivery_reference?:?string, notes?:?string}  $attrs
     */
    public function openFor(Asn $asn, ?int $userId = null, array $attrs = []): GoodsReceipt
    {
        return DB::transaction(function () use ($asn, $userId, $attrs): GoodsReceipt {
            $locked = Asn::query()->withoutGlobalScopes()->whereKey($asn->id)->lockForUpdate()->firstOrFail();

            $open = $this->openReceipt($locked);
            if ($open !== null) {
                if (($attrs['delivery_reference'] ?? null) !== null && $open->delivery_reference === null) {
                    $open->update(['delivery_reference' => $attrs['delivery_reference']]);
                }

                return $open;
            }

            $batch = (int) GoodsReceipt::query()->withoutGlobalScopes()->where('asn_id', $locked->id)->max('batch_no') + 1;

            return GoodsReceipt::query()->create([
                'receipt_no' => $locked->asn_no.'-R'.$batch,
                'asn_id' => $locked->id,
                'client_id' => $locked->client_id,
                'warehouse_id' => $locked->warehouse_id,
                'job_id' => $locked->job_id,
                'batch_no' => $batch,
                'status' => 'open',
                'unplanned' => (bool) $locked->unplanned,
                'delivery_reference' => $attrs['delivery_reference'] ?? null,
                'opened_at' => now(),
                'opened_by' => $userId,
                'notes' => $attrs['notes'] ?? null,
            ]);
        });
    }

    /** Snapshot one received goods line into the batch and stamp its stock units (including the -DMG unit). Called by ReceivingService. */
    public function recordLine(GoodsReceipt $receipt, AsnLine $line, array $units, ?int $userId = null): GoodsReceiptLine
    {
        $units = collect($units);

        $receiptLine = GoodsReceiptLine::query()->updateOrCreate(['goods_receipt_id' => $receipt->id, 'asn_line_id' => $line->id], [
            'expected_cartons' => (int) $line->expected_cartons,
            'received_cartons' => (int) $line->received_cartons,
            'damaged_cartons' => (int) $line->damaged_cartons,
            'variance_reason' => $line->variance_reason,
            'unit_count' => $units->count(),
            'pallet_count' => $units->where('unit_type', 'pallet')->count(),
            'received_at' => now(),
            'received_by' => $userId,
        ]);

        if ($units->isNotEmpty()) {
            StockUnit::query()->withoutGlobalScopes()->whereIn('id', $units->pluck('id'))->update(['goods_receipt_id' => $receipt->id]);
        }

        return $receiptLine;
    }

    /** 入库完成: refuse unless open with ≥ 1 line; snapshot totals, store + attach the PDF, mark the ASN fully received when it is. */
    public function complete(GoodsReceipt $receipt, ?int $userId = null, ?string $notes = null): GoodsReceipt
    {
        if (! $receipt->isOpen()) {
            throw new InvalidArgumentException(__('warehouse.receipts.errors.not_open', ['no' => $receipt->receipt_no]));
        }
        if ($receipt->lines()->doesntExist()) {
            throw new InvalidArgumentException(__('warehouse.receipts.errors.no_lines', ['no' => $receipt->receipt_no]));
        }

        return DB::transaction(function () use ($receipt, $userId, $notes): GoodsReceipt {
            $lines = $receipt->lines()->get();
            $receipt->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => $userId,
                'notes' => $notes !== null && $notes !== '' ? $notes : $receipt->notes,
                'expected_cartons' => (int) $lines->sum('expected_cartons'),
                'received_cartons' => (int) $lines->sum('received_cartons'),
                'damaged_cartons' => (int) $lines->sum('damaged_cartons'),
                'variance_cartons' => (int) $lines->sum(fn (GoodsReceiptLine $l) => $l->variance()),
                'line_count' => $lines->count(),
                'unit_count' => (int) $lines->sum('unit_count'),
                'pallet_count' => (int) $lines->sum('pallet_count'),
            ]);
            $receipt->refresh();

            $path = 'receipts/'.now()->format('Y/m').'/'.$receipt->receipt_no.'.pdf';
            Storage::disk('local')->put($path, $this->pdf($receipt));
            $documentId = $this->documents->attach('goods_receipt', 'asn', $receipt->asn_id, $path, [
                'job_id' => $receipt->job_id, 'client_id' => $receipt->client_id, 'client_visible' => true,
                'original_name' => $receipt->receipt_no.'.pdf', 'mime' => 'application/pdf', 'size_bytes' => Storage::disk('local')->size($path), 'uploaded_by' => $userId,
            ]);
            $receipt->update(['pdf_document_id' => $documentId]);

            $asn = Asn::query()->withoutGlobalScopes()->findOrFail($receipt->asn_id);
            if ($asn->receiving_completed_at === null && $this->allLinesReceived($asn)) {
                $asn->update(['receiving_completed_at' => now()]);
            }

            return $receipt->fresh();
        });
    }

    /** A4 PDF rendered live from the database (an open batch is labelled 草稿). */
    public function pdf(GoodsReceipt $receipt): string
    {
        $receipt->loadMissing(['asn.containers', 'client', 'warehouse', 'job', 'openedBy', 'completedBy', 'lines.asnLine.container']);
        $asn = $receipt->asn;
        $labels = StockUnit::query()->withoutGlobalScopes()->where('goods_receipt_id', $receipt->id)->orderBy('id')->get(['asn_line_id', 'label_code'])->groupBy('asn_line_id')->map(fn ($g) => $g->pluck('label_code')->all());
        $cjkFont = $this->cjkFont();

        // A full CJK TrueType font is 10–25 MB; php-font-lib parses it in memory (≈ 100 MB peak on first use, the metrics are then cached in
        // storage/fonts) and subsets it on every render. Give the render room instead of dying at PHP's 128 MB default.
        $memoryLimit = ini_get('memory_limit');
        if ($cjkFont !== null && $this->bytes($memoryLimit) < 512 * 1024 * 1024 && $this->bytes($memoryLimit) > 0) {
            ini_set('memory_limit', '512M');
        }

        try {
            return Pdf::loadView('warehouse::receipts.pdf', [
                'receipt' => $receipt,
                'asn' => $asn,
                'labels' => $labels,
                'totalBatches' => GoodsReceipt::query()->withoutGlobalScopes()->where('asn_id', $asn->id)->count(),
                'rollup' => $this->rollup($asn),
                'cjkFont' => $cjkFont,
            ])->setPaper('a4', 'landscape')->setOption('enable_font_subsetting', true)->output();
        } finally {
            gc_collect_cycles(); // dompdf leaves a cyclic object graph (~60 MB with the CJK font) that PHP only frees on a GC run
            if ($memoryLimit !== false && $memoryLimit !== ini_get('memory_limit')) {
                ini_set('memory_limit', $memoryLimit);
            }
        }
    }

    /**
     * 无预报收货 (reading B): one screen = one delivery. Creates the unplanned ASN, its lines (expected = received + damaged, nothing was
     * pre-advised), receives every row into the receiving location with auto-generated units and completes the batch at once.
     * The unplanned ASN still needs a Coordinator's confirmUnplanned() before PutawayService accepts it.
     *
     * @param  array{client_id:int, warehouse_id:int, inbound_type:string, receiving_location_id:int, delivery_reference?:?string, notes?:?string, rows:list<array{consignment_mark?:?string, description:string, received_cartons:int, damaged_cartons?:int, unit_type:string, unit_count?:?int, weight_kg?:?float, variance_reason?:?string}>}  $data
     */
    public function receiveUnplanned(array $data, int $userId): GoodsReceipt
    {
        $location = Location::query()->findOrFail($data['receiving_location_id']);
        if ($location->type !== 'receiving' || (int) $location->warehouse_id !== (int) $data['warehouse_id']) {
            throw new InvalidArgumentException(__('warehouse.receiving.unplanned.bad_location'));
        }

        return DB::transaction(function () use ($data, $location, $userId): GoodsReceipt {
            $rows = array_values($data['rows']);
            $asn = $this->asns->create([
                'client_id' => (int) $data['client_id'],
                'warehouse_id' => (int) $data['warehouse_id'],
                'inbound_type' => $data['inbound_type'],
                'expected_date' => today()->toDateString(),
                'reference' => $data['delivery_reference'] ?? null,
                'unplanned' => true,
                'created_by_type' => 'coordinator',
                'notes' => $data['notes'] ?? null,
            ]);
            $lines = $this->asns->addLines($asn, array_map(fn (array $r) => [
                'consignment_mark' => $r['consignment_mark'] ?? null,
                'description' => $r['description'],
                'expected_cartons' => (int) $r['received_cartons'] + (int) ($r['damaged_cartons'] ?? 0),
                'weight_kg' => isset($r['weight_kg']) && $r['weight_kg'] !== '' ? (float) $r['weight_kg'] : null,
            ], $rows));

            $receipt = $this->openFor($asn, $userId, ['delivery_reference' => $data['delivery_reference'] ?? null]);
            $receiving = app(ReceivingService::class);
            foreach ($rows as $i => $row) {
                $receiving->receiveLine($lines[$i], [
                    'received_cartons' => (int) $row['received_cartons'],
                    'damaged_cartons' => (int) ($row['damaged_cartons'] ?? 0),
                    'variance_reason' => $row['variance_reason'] ?? null,
                    'units' => $this->unitsFor($row),
                ], $location, $userId);
            }

            return $this->complete($receipt->fresh(), $userId, $data['notes'] ?? null);
        });
    }

    /** Whole-ASN figures for the ASN page, the receipt page and the PDF's 本预报单汇总 block. */
    public function rollup(Asn $asn): array
    {
        $lines = AsnLine::query()->where('asn_id', $asn->id)->with(['receiptLine', 'stockUnits'])->get();

        $expected = (int) $lines->sum('expected_cartons');
        $received = (int) $lines->sum('received_cartons');
        $damaged = (int) $lines->sum('damaged_cartons');

        return [
            'expected' => $expected,
            'received' => $received,
            'damaged' => $damaged,
            'variance' => $received + $damaged - $expected,
            'received_lines' => $lines->filter(fn (AsnLine $l) => $l->isReceived())->count(),
            'total_lines' => $lines->count(),
        ];
    }

    /** Which 入库单 the next receipt on this ASN joins: the open batch, or the number the next batch will get. */
    public function nextReceiptNo(Asn $asn): array
    {
        $open = $this->openReceipt($asn);
        if ($open !== null) {
            return ['no' => $open->receipt_no, 'open' => true];
        }

        return ['no' => $asn->asn_no.'-R'.((int) GoodsReceipt::query()->withoutGlobalScopes()->where('asn_id', $asn->id)->max('batch_no') + 1), 'open' => false];
    }

    public function cjkFont(): ?string
    {
        $font = config('erp.pdf_cjk_font');

        return is_string($font) && $font !== '' && is_file($font) ? $font : null;
    }

    /** php.ini shorthand ("128M", "1G", "-1") → bytes; -1 / 0 = unlimited. */
    private function bytes(string|false $limit): int
    {
        if ($limit === false || trim($limit) === '' || (int) $limit <= 0) {
            return 0;
        }
        $value = (int) $limit;

        return match (strtoupper(substr(trim($limit), -1))) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => $value,
        };
    }

    private function openReceipt(Asn $asn): ?GoodsReceipt
    {
        return GoodsReceipt::query()->withoutGlobalScopes()->where('asn_id', $asn->id)->where('status', 'open')->orderBy('batch_no')->first();
    }

    private function allLinesReceived(Asn $asn): bool
    {
        $lines = AsnLine::query()->where('asn_id', $asn->id);

        return $lines->exists() && ! (clone $lines)->whereDoesntHave('receiptLine')->whereDoesntHave('stockUnits')->exists();
    }

    /** Cartons split evenly across the row's units (remainder onto the first units); an optional total weight split the same way. */
    private function unitsFor(array $row): array
    {
        $received = (int) $row['received_cartons'];
        if ($received <= 0) {
            return [];
        }
        $count = min($received, max(1, (int) ($row['unit_count'] ?? 1)));
        $base = intdiv($received, $count);
        $remainder = $received % $count;
        $weight = isset($row['weight_kg']) && $row['weight_kg'] !== '' && $row['weight_kg'] !== null ? round((float) $row['weight_kg'] / $count, 3) : null;
        $isPallet = $row['unit_type'] === 'pallet';

        $units = [];
        for ($i = 0; $i < $count; $i++) {
            $units[] = [
                'unit_type' => $row['unit_type'],
                'carton_qty' => $base + ($i < $remainder ? 1 : 0),
                'weight_kg' => $weight,
                'pallet_source' => $isPallet ? ($row['pallet_source'] ?? 'client_own') : null,
            ];
        }

        return $units;
    }
}
