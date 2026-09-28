<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Events\StockTransferDispatched;
use App\Modules\Warehouse\Events\StockTransferReceived;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Pallet;
use App\Modules\Warehouse\Models\StockTransfer;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\Exceptions\RuleViolation;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CHANGE_REQUESTS #167 跨仓调拨: draft (lines resolved from pallet / unit codes) → dispatch (goods into the origin's transit location,
 * not allocatable, `stock.transfer.dispatched`) → receive (goods at the destination dock as not-yet-put-away stock, the ordinary putaway
 * takes over, `stock.transfer.received`). A pallet travels whole. Nothing here decides who pays: `charge_to` is the planner's choice on
 * the form — Billing's rules charge the client-requested case only, and the billing warehouse of the goods follows them only then.
 */
final class StockTransferService
{
    public function __construct(private readonly StockLedger $ledger, private readonly OutboxPublisher $outbox) {}

    /**
     * @param  array{client_id:int, from_warehouse_id:int, to_warehouse_id:int, charge_to:string, codes:list<string>, notes?:?string}  $data
     */
    public function create(array $data, int $userId): StockTransfer
    {
        if ((int) $data['from_warehouse_id'] === (int) $data['to_warehouse_id']) {
            throw new RuleViolation('Origin and destination are the same warehouse.', 'warehouse.transfers.errors.same_warehouse');
        }
        if (! in_array($data['charge_to'], StockTransfer::CHARGE_TO, true)) {
            throw new RuleViolation('Unknown charge_to.', 'warehouse.transfers.errors.charge_to');
        }
        $units = $this->resolve($data['codes'], (int) $data['client_id'], (int) $data['from_warehouse_id']);

        return DB::transaction(function () use ($data, $units, $userId): StockTransfer {
            $transfer = StockTransfer::query()->create([
                'transfer_no' => DocumentNumbers::next(StockTransfer::query(), 'transfer_no', 'TRF'),
                'client_id' => (int) $data['client_id'],
                'job_id' => (int) $units->first()->job_id,
                'from_warehouse_id' => (int) $data['from_warehouse_id'],
                'to_warehouse_id' => (int) $data['to_warehouse_id'],
                'charge_to' => $data['charge_to'],
                'status' => 'draft',
                'notes' => filled($data['notes'] ?? null) ? mb_substr(trim((string) $data['notes']), 0, 255) : null,
                'created_by' => $userId,
            ]);
            foreach ($units as $unit) {
                $transfer->lines()->create(['stock_unit_id' => $unit->id, 'pallet_id' => $unit->pallet_id, 'qty' => $unit->qty_on_hand, 'from_location_id' => $unit->location_id]);
            }

            return $transfer->fresh();
        });
    }

    /** @param  array{vehicle?:?string, driver_name?:?string, notes?:?string}  $data */
    public function dispatch(StockTransfer $transfer, array $data, int $userId): StockTransfer
    {
        if ($transfer->status !== 'draft') {
            throw new RuleViolation("Transfer {$transfer->transfer_no} is not a draft.", 'warehouse.transfers.errors.not_draft', ['no' => $transfer->transfer_no]);
        }

        return DB::transaction(function () use ($transfer, $data, $userId): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $units = $this->lockedUnits($transfer);
            foreach ($units as $unit) {
                $this->assertMovable($unit, $transfer->client_id, $transfer->from_warehouse_id);
            }
            $transit = $this->transitLocation($transfer->fromWarehouse);
            foreach ($units as $unit) {
                $this->ledger->record($unit, 'transfer', 0, ['from_location_id' => $unit->location_id, 'to_location_id' => $transit->id, 'source_type' => 'transfer', 'source_id' => $transfer->id, 'operator_id' => $userId]);
                $unit->update(['putaway_completed' => false]); // in transit: not allocatable until put away at the destination
            }
            Pallet::query()->whereIn('id', $units->pluck('pallet_id')->filter()->unique())->update(['location_id' => $transit->id, 'putaway_completed' => false]);
            $transfer->update([
                'status' => 'dispatched', 'dispatched_by' => $userId, 'dispatched_at' => now(),
                'vehicle' => filled($data['vehicle'] ?? null) ? mb_substr(trim((string) $data['vehicle']), 0, 100) : null,
                'driver_name' => filled($data['driver_name'] ?? null) ? mb_substr(trim((string) $data['driver_name']), 0, 100) : null,
                'notes' => filled($data['notes'] ?? null) ? mb_substr(trim((string) $data['notes']), 0, 255) : $transfer->notes,
            ]);
            $this->outbox->publish(new StockTransferDispatched($this->payload($transfer->fresh(), $units) + ['dispatched_by' => $userId, 'dispatched_at' => now()->toIso8601String()],
                jobId: $transfer->job_id, clientId: $transfer->client_id, correlationId: $transfer->transfer_no));

            return $transfer->fresh();
        });
    }

    public function receive(StockTransfer $transfer, Location $receivingLocation, int $userId): StockTransfer
    {
        if ($transfer->status !== 'dispatched') {
            throw new RuleViolation("Transfer {$transfer->transfer_no} is not in transit.", 'warehouse.transfers.errors.not_dispatched', ['no' => $transfer->transfer_no]);
        }
        if ($receivingLocation->type !== 'receiving' || (int) $receivingLocation->warehouse_id !== (int) $transfer->to_warehouse_id || ! $receivingLocation->active) {
            throw new RuleViolation('Transfers are received into a receiving location of the destination warehouse.', 'warehouse.transfers.errors.bad_receiving_location');
        }

        return DB::transaction(function () use ($transfer, $receivingLocation, $userId): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $units = $this->lockedUnits($transfer);
            $billing = $transfer->isClientRequested() ? ['billing_warehouse_id' => $transfer->to_warehouse_id] : [];
            foreach ($units as $unit) {
                $this->ledger->record($unit, 'transfer', 0, ['from_location_id' => $unit->location_id, 'to_location_id' => $receivingLocation->id, 'source_type' => 'transfer', 'source_id' => $transfer->id, 'operator_id' => $userId]);
                $unit->update(['warehouse_id' => $transfer->to_warehouse_id, 'putaway_completed' => false] + $billing);
            }
            Pallet::query()->whereIn('id', $units->pluck('pallet_id')->filter()->unique())
                ->update(['warehouse_id' => $transfer->to_warehouse_id, 'location_id' => $receivingLocation->id, 'putaway_completed' => false] + $billing);
            $transfer->update(['status' => 'received', 'received_by' => $userId, 'received_at' => now(), 'receiving_location_id' => $receivingLocation->id]);
            $this->outbox->publish(new StockTransferReceived($this->payload($transfer->fresh(), $units) + ['received_by' => $userId, 'received_at' => now()->toIso8601String(), 'receiving_location_id' => $receivingLocation->id],
                jobId: $transfer->job_id, clientId: $transfer->client_id, correlationId: $transfer->transfer_no));

            return $transfer->fresh();
        });
    }

    public function cancel(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->status !== 'draft') {
            throw new RuleViolation("Transfer {$transfer->transfer_no} is not a draft.", 'warehouse.transfers.errors.not_draft', ['no' => $transfer->transfer_no]);
        }
        $transfer->update(['status' => 'cancelled']);

        return $transfer->fresh();
    }

    /**
     * Pallet codes (P<id> / P-000123) bring every unit on the pallet; unit codes (U<id> / label) bring the unit — and its whole pallet,
     * since a pallet never splits. Every unit must belong to the client, sit put away and good in the origin warehouse, carry no
     * reservation / frozen cartons, be on no other open transfer, and share one Job.
     *
     * @param  list<string>  $codes
     * @return Collection<int, StockUnit>
     */
    private function resolve(array $codes, int $clientId, int $fromWarehouseId): Collection
    {
        $units = collect();
        foreach (array_filter(array_map('trim', $codes)) as $code) {
            $pallet = Pallet::query()->scanCode($code)->first();
            if ($pallet === null) {
                $unit = StockUnit::query()->withoutGlobalScopes()->scanCode($code)->first();
                if ($unit === null) {
                    throw new RuleViolation("Unknown code {$code}.", 'warehouse.transfers.errors.unknown_code', ['code' => $code]);
                }
                $pallet = $unit->pallet_id !== null ? $unit->pallet : null;
                if ($pallet === null) {
                    $units->put($unit->id, $unit);

                    continue;
                }
            }
            foreach (StockUnit::query()->withoutGlobalScopes()->where('pallet_id', $pallet->id)->where('qty_on_hand', '>', 0)->orderBy('id')->get() as $onPallet) {
                $units->put($onPallet->id, $onPallet);
            }
        }
        if ($units->isEmpty()) {
            throw new RuleViolation('Nothing to transfer.', 'warehouse.transfers.errors.no_lines');
        }
        foreach ($units as $unit) {
            $this->assertMovable($unit, $clientId, $fromWarehouseId);
        }
        if ($units->pluck('job_id')->unique()->count() > 1) {
            throw new RuleViolation('One transfer carries the goods of one Job.', 'warehouse.transfers.errors.mixed_jobs');
        }
        $busy = StockTransfer::query()->whereIn('status', ['draft', 'dispatched'])->whereHas('lines', fn ($q) => $q->whereIn('stock_unit_id', $units->keys()))->value('transfer_no');
        if ($busy !== null) {
            throw new RuleViolation("Already on transfer {$busy}.", 'warehouse.transfers.errors.already_on_transfer', ['no' => $busy]);
        }

        return $units->values();
    }

    private function assertMovable(StockUnit $unit, int $clientId, int $fromWarehouseId): void
    {
        if ((int) $unit->client_id !== $clientId) {
            throw new RuleViolation("{$unit->label_code} belongs to another client.", 'warehouse.transfers.errors.other_client', ['label' => $unit->label_code]);
        }
        if ((int) $unit->warehouse_id !== $fromWarehouseId) {
            throw new RuleViolation("{$unit->label_code} is not in the origin warehouse.", 'warehouse.transfers.errors.not_in_origin', ['label' => $unit->label_code]);
        }
        if (! $unit->putaway_completed || $unit->condition !== 'good' || $unit->qty_on_hand <= 0) {
            throw new RuleViolation("{$unit->label_code} is not put-away good stock.", 'warehouse.transfers.errors.not_available', ['label' => $unit->label_code]);
        }
        if ($unit->qty_reserved > 0 || $unit->qty_frozen > 0) {
            throw new RuleViolation("{$unit->label_code} has reserved or frozen cartons.", 'warehouse.transfers.errors.reserved', ['label' => $unit->label_code]);
        }
    }

    /** @return Collection<int, StockUnit> */
    private function lockedUnits(StockTransfer $transfer): Collection
    {
        return StockUnit::query()->withoutGlobalScopes()->whereIn('id', $transfer->lines()->pluck('stock_unit_id'))->lockForUpdate()->orderBy('id')->get();
    }

    /** The origin's transit area (`WH-TRN-01-01`, type transit), created on first use. */
    private function transitLocation(Warehouse $warehouse): Location
    {
        return Location::query()->firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'full_code' => Location::buildFullCode($warehouse->code, 'TRN', '01', '01')],
            ['zone' => 'TRN', 'aisle' => '01', 'bay' => '01', 'type' => 'transit', 'active' => true],
        );
    }

    /**
     * @param  Collection<int, StockUnit>  $units
     * @return array<string, mixed>
     */
    private function payload(StockTransfer $transfer, Collection $units): array
    {
        return [
            'transfer_id' => $transfer->id,
            'transfer_no' => $transfer->transfer_no,
            'client_id' => $transfer->client_id,
            'job_id' => $transfer->job_id,
            'from_warehouse_id' => $transfer->from_warehouse_id,
            'to_warehouse_id' => $transfer->to_warehouse_id,
            'charge_to' => $transfer->charge_to,
            'pallet_count' => $units->pluck('pallet_id')->filter()->unique()->count(),
            'loose_unit_count' => $units->whereNull('pallet_id')->count(),
            'unit_count' => $units->count(),
            'carton_count' => (int) $units->sum('qty_on_hand'),
            'lines' => $units->map(fn (StockUnit $u) => ['stock_unit_id' => $u->id, 'pallet_id' => $u->pallet_id, 'asn_line_id' => $u->asn_line_id, 'qty' => (int) $u->qty_on_hand])->values()->all(),
        ];
    }
}
