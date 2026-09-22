<?php

namespace App\Modules\Warehouse\Services;

use App\Models\User;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Contracts\RateService;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * 修改单元信息 (audit 2026-09-22 INBOUND-05, CR #141): after receiving, admin / warehouse_supervisor correct a stock unit's pallet
 * source, dimensions and weight. The pallet class is re-suggested from the client's thresholds (RateService::suggestPalletClass, the
 * receiving rule) unless one is chosen explicitly; a reason is required and the change is written to the activity log.
 *
 * Billing follows the daily snapshot (StorageBillingService bills each ISO week from the week's LAST snapshot), so the corrected class /
 * source reaches the next weekly storage and pallet-rental run on its own; a week already billed is not re-run (reverse by hand).
 */
final class UnitCorrectionService
{
    public const FIELDS = ['pallet_source', 'length_mm', 'width_mm', 'height_mm', 'weight_kg', 'pallet_class'];

    public function __construct(private readonly RateService $rates) {}

    /**
     * @param  array{pallet_source?:?string, length_mm?:?int, width_mm?:?int, height_mm?:?int, weight_kg?:?float, pallet_class?:?string}  $data  empty / null values are ignored except pallet_class (null = re-suggest)
     * @return array<string, array{0:mixed, 1:mixed}> the changed attributes → [old, new]
     *
     * @throws RuleViolation `warehouse.stock.correct.not_pallet_source` for a pallet source / class on a carton unit, `warehouse.stock.correct.nothing` when nothing changed
     */
    public function correct(StockUnit $unit, array $data, string $reason, ?int $userId = null): array
    {
        $isPallet = $unit->unit_type === 'pallet';
        if (! $isPallet && (filled($data['pallet_source'] ?? null) || filled($data['pallet_class'] ?? null))) {
            throw new RuleViolation("Unit {$unit->label_code} is a carton unit: no pallet source / class.", 'warehouse.stock.correct.not_pallet_source');
        }

        return DB::transaction(function () use ($unit, $data, $reason, $userId, $isPallet): array {
            $unit = StockUnit::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($unit->id);
            $old = $unit->only([...self::FIELDS, 'pallet_class_overridden_reason']);

            foreach (['length_mm', 'width_mm', 'height_mm'] as $dim) {
                if (filled($data[$dim] ?? null)) {
                    $unit->{$dim} = (int) $data[$dim];
                }
            }
            if (filled($data['weight_kg'] ?? null)) {
                $unit->weight_kg = (float) $data['weight_kg'];
            }
            if ($isPallet && filled($data['pallet_source'] ?? null)) {
                $unit->pallet_source = $data['pallet_source'];
            }
            if ($isPallet) {
                $suggested = $unit->length_mm && $unit->width_mm && $unit->height_mm && $unit->weight_kg !== null
                    ? $this->rates->suggestPalletClass((int) $unit->client_id, (int) $unit->length_mm, (int) $unit->width_mm, (int) $unit->height_mm, (float) $unit->weight_kg)
                    : $unit->pallet_class;
                $chosen = filled($data['pallet_class'] ?? null) ? $data['pallet_class'] : $suggested;
                $unit->pallet_class = $chosen;
                $unit->pallet_class_overridden_reason = $chosen !== $suggested ? $reason : null;
            }

            $changes = [];
            foreach (array_keys($old) as $field) {
                if ((string) $old[$field] !== (string) $unit->{$field}) {
                    $changes[$field] = [$old[$field], $unit->{$field}];
                }
            }
            if ($changes === []) {
                throw new RuleViolation("Nothing changed on unit {$unit->label_code}.", 'warehouse.stock.correct.nothing');
            }

            $unit->save();
            $log = activity('stock_unit')->performedOn($unit)->withProperties(['old' => array_map(fn ($c) => $c[0], $changes), 'attributes' => array_map(fn ($c) => $c[1], $changes), 'reason' => $reason]);
            if ($userId !== null && ($user = User::query()->find($userId)) !== null) {
                $log->causedBy($user);
            }
            $log->log('unit_corrected');

            return $changes;
        });
    }
}
