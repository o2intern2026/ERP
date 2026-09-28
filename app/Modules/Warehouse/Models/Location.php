<?php

namespace App\Modules\Warehouse\Models;

use App\Modules\Warehouse\Services\ScanCodes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Location code (CHANGE_REQUESTS #164, the industry Zone-Aisle-Bay-Level-Position scheme, ERP_PLAN §4.2):
 *   - rack slot:  WH-ZONE-AISLE-BAY-LEVEL-POSITION, e.g. MEL1-A-01-03-2-1 = warehouse MEL1, zone A, aisle 01, bay 03 (the rack column along
 *                 the aisle — odd bays on the left-hand side, even on the right), level 2 (1 = floor beam), position 1 (left slot; 2 = right);
 *   - floor area: WH-ZONE-AISLE-BAY, e.g. MEL1-RCV-01-01 (receiving, staging, packing, quarantine, floor-stacked storage) — no level, no position.
 * Columns: zone, aisle, bay (the four-level code's bin), rack_level (the level), position. Zero-padded numbers keep string order = walk order.
 * CHANGE_REQUESTS #126: storage_tier (standard | bottom, storage locations only); tier and level changes are logged (activitylog `location`).
 */
class Location extends Model
{
    use LogsActivity;

    /** Slots of a bay at one level: 1 = left, 2 = right. */
    public const POSITIONS = [1, 2];

    protected $fillable = ['warehouse_id', 'zone', 'aisle', 'bay', 'full_code', 'type', 'storage_tier', 'rack_level', 'position', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'rack_level' => 'integer', 'position' => 'integer'];
    }

    protected static function booted(): void
    {
        // The code follows the parts: a new row without a code gets one; an existing row whose parts change (bulk level edit) is re-coded.
        // A new row created with an explicit code keeps it (seeders, fixtures).
        static::saving(function (Location $location): void {
            if (blank($location->full_code) || ($location->exists && $location->isDirty(['zone', 'aisle', 'bay', 'rack_level', 'position']))) {
                $code = $location->relationLoaded('warehouse') && $location->warehouse !== null
                    ? $location->warehouse->code
                    : Warehouse::query()->whereKey($location->warehouse_id)->value('code');
                $location->full_code = self::buildFullCode((string) $code, (string) $location->zone, (string) $location->aisle, (string) $location->bay, $location->rack_level, $location->position);
            }
        });
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** A bottom-level storage location (#126) — the only kind that carries the bottom-level surcharge. */
    public function isBottom(): bool
    {
        return $this->type === 'storage' && $this->storage_tier === 'bottom';
    }

    /** A rack slot (level + position in the code) as opposed to a floor area. */
    public function isRack(): bool
    {
        return $this->rack_level !== null && $this->position !== null;
    }

    /** Which side of the aisle a numeric bay is on — odd = left, even = right (#164); null for a non-numeric bay. */
    public function side(): ?string
    {
        return ctype_digit((string) $this->bay) ? (((int) $this->bay) % 2 === 1 ? 'left' : 'right') : null;
    }

    /** The location a scan names: the label's short token `L<id>` or the full full_code, case-insensitive (CHANGE_REQUESTS #131). */
    public function scopeScanCode(Builder $query, string $code): Builder
    {
        return ScanCodes::whereLocation($query, $code);
    }

    /** Is $value inside the inclusive from–to range of zone / aisle / bay codes? Numeric codes compare as numbers, others case-insensitively; an empty bound is open. */
    public static function codeBetween(string $value, ?string $from, ?string $to): bool
    {
        $cmp = fn (string $a, string $b): int => ctype_digit($a) && ctype_digit($b) ? ((int) $a <=> (int) $b) : strcasecmp($a, $b);

        return (! filled($from) || $cmp($value, trim($from)) >= 0) && (! filled($to) || $cmp($value, trim($to)) <= 0);
    }

    /**
     * WH-ZONE-AISLE-BAY, plus -LEVEL-POSITION when both are given (a rack slot). Upper-cased; the parts are stored as typed
     * (zero-pad numbers yourself — the generator does).
     */
    public static function buildFullCode(string $warehouseCode, string $zone, string $aisle, string $bay, ?int $level = null, ?int $position = null): string
    {
        $parts = [$warehouseCode, $zone, $aisle, $bay];
        if ($level !== null && $position !== null) {
            $parts[] = (string) $level;
            $parts[] = (string) $position;
        }

        return strtoupper(implode('-', $parts));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['storage_tier', 'rack_level', 'position'])->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('location');
    }
}
