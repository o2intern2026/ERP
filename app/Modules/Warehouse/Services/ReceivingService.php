<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\RateService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * B2 receiving: per goods line record received / damaged cartons, create stock units in the receiving location
 * (not yet available), suggest pallet_class from the client's thresholds (§4.8), raise a discrepancy exception when
 * received ≠ expected (§4.3). Truck (LCL) receiving records the unloaded pallet count on a receiving task.
 * Every received line is snapshotted into the ASN's open 入库单 batch (GoodsReceiptService) and its units carry goods_receipt_id.
 */
final class ReceivingService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly RateService $rates,
        private readonly ExceptionService $exceptions,
        private readonly GoodsReceiptService $receipts,
    ) {}

    /**
     * @param  array{received_cartons:int, damaged_cartons?:int, variance_reason?:?string, units:list<array{unit_type:string, carton_qty:int, length_mm?:?int, width_mm?:?int, height_mm?:?int, weight_kg?:?float, pallet_source?:?string, pallet_class?:?string, pallet_class_reason?:?string}>}  $data
     * @param  ?int  $actorId  who received (defaults to the signed-in user); stamped on the 入库单 line
     * @return list<StockUnit> the units created (the line's 入库单 is reachable via AsnLine::receiptLine()->receipt)
     */
    public function receiveLine(AsnLine $line, array $data, Location $receivingLocation, ?int $actorId = null): array
    {
        if ($receivingLocation->type !== 'receiving') {
            throw new InvalidArgumentException('Goods are received into a receiving location.');
        }

        $actorId ??= auth()->id();

        return DB::transaction(function () use ($line, $data, $receivingLocation, $actorId): array {
            $asn = $line->asn;
            if ($asn->status === 'booked') {
                $asn->update(['status' => 'receiving', 'arrived_at' => $asn->arrived_at ?? now()]);
            } elseif ($asn->status === 'arrived') {
                $asn->update(['status' => 'receiving']);
            }

            $line->update([
                'received_cartons' => $data['received_cartons'],
                'damaged_cartons' => $data['damaged_cartons'] ?? 0,
                'variance_reason' => $data['variance_reason'] ?? null,
            ]);

            $units = [];
            $seq = StockUnit::query()->withoutGlobalScopes()->where('asn_line_id', $line->id)->count();
            foreach ($data['units'] as $spec) {
                $seq++;
                $isPallet = $spec['unit_type'] === 'pallet';
                $suggested = $isPallet && isset($spec['length_mm'], $spec['width_mm'], $spec['height_mm'], $spec['weight_kg'])
                    ? $this->rates->suggestPalletClass($asn->client_id, (int) $spec['length_mm'], (int) $spec['width_mm'], (int) $spec['height_mm'], (float) $spec['weight_kg'])
                    : null;
                $palletClass = $spec['pallet_class'] ?? $suggested;

                $unit = StockUnit::query()->create([
                    'client_id' => $asn->client_id,
                    'job_id' => $asn->job_id,
                    'asn_line_id' => $line->id,
                    'warehouse_id' => $asn->warehouse_id,
                    'unit_type' => $spec['unit_type'],
                    'label_code' => sprintf('%s-L%d-%02d', $asn->asn_no, $line->id, $seq),
                    'location_id' => $receivingLocation->id,
                    'qty_on_hand' => 0,
                    'pallet_class' => $isPallet ? $palletClass : null,
                    'pallet_class_overridden_reason' => ($isPallet && $palletClass !== $suggested) ? ($spec['pallet_class_reason'] ?? 'overridden at receiving') : null,
                    'length_mm' => $spec['length_mm'] ?? null,
                    'width_mm' => $spec['width_mm'] ?? null,
                    'height_mm' => $spec['height_mm'] ?? null,
                    'weight_kg' => $spec['weight_kg'] ?? null,
                    'pallet_source' => $isPallet ? ($spec['pallet_source'] ?? 'client_own') : null,
                    'condition' => 'good',
                    'putaway_completed' => false,
                    'received_at' => now(),
                ]);
                $this->ledger->record($unit, 'receipt', (int) $spec['carton_qty'], ['to_location_id' => $receivingLocation->id, 'source_type' => 'asn', 'source_id' => $asn->id]);
                $units[] = $unit;
            }

            if (($data['damaged_cartons'] ?? 0) > 0) {
                // Damaged cartons still count as received stock but are quarantined, not available (§4.3 rule 1; §4.8 storage still billed).
                $damaged = StockUnit::query()->create([
                    'client_id' => $asn->client_id, 'job_id' => $asn->job_id, 'asn_line_id' => $line->id, 'warehouse_id' => $asn->warehouse_id,
                    'unit_type' => 'carton', 'label_code' => sprintf('%s-L%d-DMG', $asn->asn_no, $line->id), 'location_id' => $receivingLocation->id,
                    'qty_on_hand' => 0, 'condition' => 'damaged', 'putaway_completed' => false, 'received_at' => now(),
                ]);
                $this->ledger->record($damaged, 'receipt', (int) $data['damaged_cartons'], ['to_location_id' => $receivingLocation->id, 'source_type' => 'asn', 'source_id' => $asn->id]);
                $units[] = $damaged;
            }

            if ($line->variance() !== 0 || ($data['damaged_cartons'] ?? 0) > 0) {
                $this->exceptions->raise('discrepancy', 'warehouse', [
                    'job_id' => $asn->job_id,
                    'client_id' => $asn->client_id,
                    'source_type' => 'asn_line',
                    'source_id' => $line->id,
                    'message' => sprintf('%s line %d: expected %d, received %d, damaged %d. %s', $asn->asn_no, $line->id, $line->expected_cartons, $line->received_cartons, $line->damaged_cartons, $data['variance_reason'] ?? ''),
                ]);
            }

            // Reading A/C (lead decision 2026-09-08): the line joins the ASN's open 入库单 batch; the operator ends the batch with 入库完成.
            $receipt = $this->receipts->openFor($asn, $actorId);
            $this->receipts->recordLine($receipt, $line, $units, $actorId);

            return $units;
        });
    }
}
