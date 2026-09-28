<?php

namespace App\Modules\Warehouse\Models;

use App\Modules\MasterData\Models\Client;
use App\Modules\Warehouse\Services\ScanCodes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * CHANGE_REQUESTS #166 托盘牌号 (licence plate): the physical pallet — one barcode (`P<id>`, printed text = pallet_no `P-000123`), one
 * location, one class / source / dims — carrying any number of stock units of ONE client and ONE Job (a mixed pallet = several goods
 * lines). Putaway, moves and whole-pallet picks act on the pallet; storage and pallet rental are billed per pallet, not per line.
 * `status`: in_use | empty (every unit on it has 0 cartons on hand). Loose cartons have no pallet.
 */
class Pallet extends Model
{
    public const STATUSES = ['in_use', 'empty'];

    protected $fillable = [
        'pallet_no', 'warehouse_id', 'billing_warehouse_id', 'client_id', 'job_id', 'location_id', 'pallet_class', 'pallet_class_overridden_reason', 'pallet_source',
        'length_mm', 'width_mm', 'height_mm', 'weight_kg', 'status', 'putaway_completed', 'received_at', 'released_at', 'reuse_count',
    ];

    protected function casts(): array
    {
        return ['weight_kg' => 'decimal:3', 'putaway_completed' => 'boolean', 'received_at' => 'datetime', 'released_at' => 'datetime', 'reuse_count' => 'integer'];
    }

    public function units(): HasMany
    {
        return $this->hasMany(StockUnit::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** The pallet a scan names: the label's short token `P<id>` or the printed pallet_no, case-insensitive. */
    public function scopeScanCode(Builder $query, string $code): Builder
    {
        return ScanCodes::wherePallet($query, $code);
    }

    /** In the free pool (CHANGE_REQUESTS #169): emptied and cleared from its slot — receiving may take it before printing a new number. */
    public function isFree(): bool
    {
        return $this->status === 'empty' && $this->location_id === null;
    }

    /** Free pallets of a warehouse, oldest release first; a source narrows it (a client-own pallet never goes back into the pool for others). */
    public function scopeFree(Builder $query, int $warehouseId, ?string $source = null): Builder
    {
        return $query->where('warehouse_id', $warehouseId)->where('status', 'empty')->whereNull('location_id')
            ->when($source !== null, fn (Builder $q) => $q->where('pallet_source', $source))
            ->where('pallet_source', '!=', 'client_own')
            ->orderBy('released_at')->orderBy('id');
    }

    /** Still at the dock and in use: more goods of the same client + Job may be stacked on it. */
    public function isReceivable(): bool
    {
        return $this->status === 'in_use' && ! $this->putaway_completed;
    }

    /** `empty` once no unit on it has cartons on hand (whole pallet picked / adjusted away); back to `in_use` when stock reappears. */
    public function refreshStatus(): void
    {
        $onHand = StockUnit::query()->withoutGlobalScopes()->where('pallet_id', $this->id)->where('qty_on_hand', '>', 0)->exists();
        $status = $onHand ? 'in_use' : 'empty';
        if ($status !== $this->status) {
            $this->update(['status' => $status]);
        }
    }

    /** Next P-000001-style number; called inside the receiving transaction (the max is read FOR UPDATE, so two docks never share a number). */
    public static function nextNumber(): string
    {
        $max = (int) DB::table('pallets')->lockForUpdate()->selectRaw('MAX(CAST(SUBSTRING(pallet_no, 3) AS UNSIGNED)) as n')->where('pallet_no', 'like', 'P-%')->value('n');

        return sprintf('P-%06d', $max + 1);
    }
}
