<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\GoodsReceiptLine;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Pallet;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\RateService;
use App\Support\Exceptions\RuleViolation;
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
        private readonly PutawayService $putaway,
    ) {}

    /**
     * @param  array{received_cartons:int, damaged_cartons?:int, variance_reason?:?string, units:list<array{unit_type:string, carton_qty:int, length_mm?:?int, width_mm?:?int, height_mm?:?int, weight_kg?:?float, pallet_source?:?string, pallet_class?:?string, pallet_class_reason?:?string}>}  $data
     * @param  ?int  $actorId  who received (defaults to the signed-in user); stamped on the 入库单 line
     * @return list<StockUnit> the units created (the line's 入库单 is reachable via AsnLine::receiptLine()->receipt)
     *
     * @throws RuleViolation `warehouse.receiving.bulk.already_received` when the line was received before (audit 2026-09-22 INBOUND-02: a
     *                       browser Back + resubmit or a bookmarked form used to book the line twice — a second set of units and a second
     *                       ledger receipt), `warehouse.receiving.bulk.not_receivable` when the ASN is past receiving
     */
    public function receiveLine(AsnLine $line, array $data, Location $receivingLocation, ?int $actorId = null): array
    {
        if ($receivingLocation->type !== 'receiving') {
            throw new InvalidArgumentException('Goods are received into a receiving location.');
        }

        $actorId ??= auth()->id();

        return DB::transaction(function () use ($line, $data, $receivingLocation, $actorId): array {
            // The goods line is held FOR UPDATE while "was it received already?" is answered, and the answer comes from locking reads:
            // under REPEATABLE READ a plain SELECT after waiting on the lock would still show the snapshot from before the other
            // submit committed (see GoodsReceiptService::openFor), so the second of two overlapping submits must see the first one's rows.
            $locked = AsnLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $received = GoodsReceiptLine::query()->where('asn_line_id', $locked->id)->lockForUpdate()->value('id') !== null
                || StockUnit::query()->withoutGlobalScopes()->where('asn_line_id', $locked->id)->lockForUpdate()->value('id') !== null;
            if ($received) {
                throw new RuleViolation("ASN line {$locked->id} has already been received.", 'warehouse.receiving.bulk.already_received', ['line' => $locked->id]);
            }
            $asn = Asn::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->asn_id);
            if (! in_array($asn->status, ['booked', 'arrived', 'receiving'], true)) {
                throw new RuleViolation("ASN {$asn->asn_no} is not receivable in status {$asn->status}.", 'warehouse.receiving.bulk.not_receivable');
            }

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
                if ($isPallet) {
                    // CHANGE_REQUESTS #170: the source's preset footprint / tare fills what the operator left blank (typed values win).
                    foreach (PalletService::spec($spec['pallet_source'] ?? 'warehouse_plain') ?? [] as $key => $preset) {
                        $spec[$key] = ($spec[$key] ?? null) === null || $spec[$key] === '' ? $preset : $spec[$key];
                    }
                }
                $suggested = $isPallet && isset($spec['length_mm'], $spec['width_mm'], $spec['height_mm'], $spec['weight_kg'])
                    ? $this->rates->suggestPalletClass($asn->client_id, (int) $spec['length_mm'], (int) $spec['width_mm'], (int) $spec['height_mm'], (float) $spec['weight_kg'])
                    : null;
                $palletClass = $spec['pallet_class'] ?? $suggested;
                // CHANGE_REQUESTS #166: a pallet-type unit is a NEW pallet unless a pallet_no names an existing one (same client + Job +
                // warehouse, still at the dock) — then the line's cartons join that pallet (mixed pallet); a carton unit with a pallet_no
                // is stacked on it, without one it stays loose. The unit sits wherever its pallet is.
                $pallet = $this->palletFor($asn, $spec, $isPallet, $palletClass, $suggested, $receivingLocation);

                $unit = StockUnit::query()->create([
                    'client_id' => $asn->client_id,
                    'job_id' => $asn->job_id,
                    'asn_line_id' => $line->id,
                    'warehouse_id' => $asn->warehouse_id,
                    'billing_warehouse_id' => $pallet?->billing_warehouse_id ?? $asn->warehouse_id, // CHANGE_REQUESTS #167: the warehouse the client booked
                    'unit_type' => $spec['unit_type'],
                    'label_code' => sprintf('%s-L%d-%02d', $asn->asn_no, $line->id, $seq),
                    'location_id' => $pallet?->location_id ?? $receivingLocation->id,
                    'pallet_id' => $pallet?->id,
                    'qty_on_hand' => 0,
                    'pallet_class' => $isPallet ? $palletClass : null,
                    'pallet_class_overridden_reason' => ($isPallet && $palletClass !== $suggested) ? ($spec['pallet_class_reason'] ?? 'overridden at receiving') : null,
                    'length_mm' => $spec['length_mm'] ?? ($isPallet ? $pallet?->length_mm : null),
                    'width_mm' => $spec['width_mm'] ?? ($isPallet ? $pallet?->width_mm : null),
                    'height_mm' => $spec['height_mm'] ?? ($isPallet ? $pallet?->height_mm : null),
                    'weight_kg' => $spec['weight_kg'] ?? ($isPallet ? $pallet?->weight_kg : null),
                    'pallet_source' => $isPallet ? ($pallet?->pallet_source ?? $spec['pallet_source'] ?? 'warehouse_plain') : null, // CHANGE_REQUESTS #170
                    'condition' => 'good',
                    'putaway_completed' => false,
                    'received_at' => now(),
                    'required_storage_tier' => $line->storage_tier ?: 'standard', // the tier declared on the goods line travels with the goods (#126)
                ]);
                $this->ledger->record($unit, 'receipt', (int) $spec['carton_qty'], ['to_location_id' => $unit->location_id, 'source_type' => 'asn', 'source_id' => $asn->id]);
                $pallet?->refreshStatus();
                $units[] = $unit;
            }

            if (($data['damaged_cartons'] ?? 0) > 0) {
                // Damaged cartons still count as received stock but are quarantined, not available (§4.3 rule 1; §4.8 storage still billed).
                $damaged = StockUnit::query()->create([
                    'client_id' => $asn->client_id, 'job_id' => $asn->job_id, 'asn_line_id' => $line->id, 'warehouse_id' => $asn->warehouse_id,
                    'billing_warehouse_id' => $asn->warehouse_id, 'unit_type' => 'carton', 'label_code' => sprintf('%s-L%d-DMG', $asn->asn_no, $line->id), 'location_id' => $receivingLocation->id,
                    'qty_on_hand' => 0, 'condition' => 'damaged', 'putaway_completed' => false, 'received_at' => now(), 'required_storage_tier' => $line->storage_tier ?: 'standard',
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

            if ($units === []) {
                // Nothing to put away for this line (received 0 — "not on truck"): if it was the last open line and every other unit is already
                // put away, the ASN completes here; putaway() would never run again for it (audit 2026-09-22 INBOUND-03).
                $this->putaway->completeIfDone($asn);
            }

            return $units;
        });
    }

    /**
     * CHANGE_REQUESTS #166: the pallet a received unit sits on — an existing one named by `pallet_no` (validated: same client, Job and
     * warehouse, in use, not yet put away), a new one for a pallet-type unit, none for a loose carton unit.
     *
     * @param  array<string, mixed>  $spec
     */
    private function palletFor(Asn $asn, array $spec, bool $isPallet, ?string $palletClass, ?string $suggested, Location $receivingLocation): ?Pallet
    {
        $palletNo = strtoupper(trim((string) ($spec['pallet_no'] ?? '')));
        $source = $spec['pallet_source'] ?? 'warehouse_plain'; // CHANGE_REQUESTS #170: our own wooden pallet unless told otherwise
        if ($palletNo !== '') {
            $pallet = Pallet::query()->scanCode($palletNo)->lockForUpdate()->first();
            // CHANGE_REQUESTS #169: a FREE pallet (emptied, cleared from its slot) is put back to work for these goods.
            if ($pallet !== null && $pallet->isFree() && $isPallet) {
                return app(PalletService::class)->reassign($pallet, $asn, $spec + ['pallet_source' => $source], $palletClass, $suggested, $receivingLocation);
            }
            if ($pallet === null || (int) $pallet->client_id !== (int) $asn->client_id || (int) $pallet->job_id !== (int) $asn->job_id
                || (int) $pallet->warehouse_id !== (int) $asn->warehouse_id || ! $pallet->isReceivable()) {
                throw new RuleViolation("Pallet {$palletNo} cannot take more goods (unknown, another client / Job / warehouse, put away or empty).", 'warehouse.receiving.errors.pallet_unusable', ['pallet' => $palletNo]);
            }

            return $pallet;
        }
        if (! $isPallet) {
            return null;
        }
        // CHANGE_REQUESTS #170: no number given → the oldest free pallet of this warehouse and source, else a new number.
        if ($source !== 'client_own') {
            $free = Pallet::query()->free($asn->warehouse_id, $source)->lockForUpdate()->first();
            if ($free !== null) {
                return app(PalletService::class)->reassign($free, $asn, $spec + ['pallet_source' => $source], $palletClass, $suggested, $receivingLocation);
            }
        }
        $defaults = PalletService::spec($source) ?? [];

        return Pallet::query()->create([
            'pallet_no' => Pallet::nextNumber(),
            'warehouse_id' => $asn->warehouse_id,
            'billing_warehouse_id' => $asn->warehouse_id,
            'client_id' => $asn->client_id,
            'job_id' => $asn->job_id,
            'location_id' => $receivingLocation->id,
            'pallet_class' => $palletClass,
            'pallet_class_overridden_reason' => $palletClass !== $suggested ? ($spec['pallet_class_reason'] ?? 'overridden at receiving') : null,
            'pallet_source' => $source,
            'length_mm' => $spec['length_mm'] ?? $defaults['length_mm'] ?? null,
            'width_mm' => $spec['width_mm'] ?? $defaults['width_mm'] ?? null,
            'height_mm' => $spec['height_mm'] ?? $defaults['height_mm'] ?? null,
            'weight_kg' => $spec['weight_kg'] ?? $defaults['weight_kg'] ?? null,
            'status' => 'in_use',
            'putaway_completed' => false,
            'received_at' => now(),
        ]);
    }
}
