<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\StockLedgerEntry;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Exceptions\RuleViolation;
use InvalidArgumentException;

/**
 * Ledger is the truth, balances are a projection (ERP_PLAN §4.3 rule 9): every on-hand change goes through record(),
 * inside the caller's transaction, writing the ledger row and the stock_units balance together.
 * Movement types that change qty_on_hand: receipt, pick, adjust, return, split, merge. putaway / transfer move
 * location only (qty 0); release records reserved-quantity changes (qty_before/after are qty_reserved).
 */
final class StockLedger
{
    public const ON_HAND_TYPES = ['receipt', 'pick', 'adjust', 'return', 'split', 'merge'];

    public const LOCATION_TYPES = ['putaway', 'transfer'];

    /**
     * @param  array{from_location_id?:int, to_location_id?:int, from_stock_unit_id?:int, to_stock_unit_id?:int, movement_group_id?:string, source_type?:string, source_id?:int, operator_id?:int}  $context
     */
    public function record(StockUnit $unit, string $movementType, int $qtyDelta, array $context = []): StockLedgerEntry
    {
        if (in_array($movementType, self::ON_HAND_TYPES, true)) {
            $before = $unit->qty_on_hand;
            $after = $before + $qtyDelta;
            if ($after < 0) {
                throw new RuleViolation("Stock unit {$unit->label_code}: on-hand would go negative ({$before} {$qtyDelta}).", 'warehouse.stock.errors.negative_on_hand', ['label' => $unit->label_code, 'before' => $before, 'delta' => $qtyDelta]);
            }
            $unit->qty_on_hand = $after;
        } elseif (in_array($movementType, self::LOCATION_TYPES, true)) {
            $before = $after = $unit->qty_on_hand;
            if (isset($context['to_location_id'])) {
                $unit->location_id = $context['to_location_id'];
            }
        } elseif ($movementType === 'release') {
            $before = $unit->qty_reserved;
            $after = max(0, $before + $qtyDelta);
            $unit->qty_reserved = $after;
        } else {
            throw new InvalidArgumentException("Unknown movement_type: {$movementType}");
        }

        $unit->save();

        return StockLedgerEntry::query()->create([
            'stock_unit_id' => $unit->id,
            'movement_type' => $movementType,
            'qty' => $qtyDelta,
            'qty_before' => $before,
            'qty_after' => $after,
            'movement_group_id' => $context['movement_group_id'] ?? null,
            'from_stock_unit_id' => $context['from_stock_unit_id'] ?? null,
            'to_stock_unit_id' => $context['to_stock_unit_id'] ?? null,
            'from_location_id' => $context['from_location_id'] ?? null,
            'to_location_id' => $context['to_location_id'] ?? null,
            'source_type' => $context['source_type'] ?? null,
            'source_id' => $context['source_id'] ?? null,
            'operator_id' => $context['operator_id'] ?? auth()->id(),
            'created_at' => now(),
        ]);
    }
}
