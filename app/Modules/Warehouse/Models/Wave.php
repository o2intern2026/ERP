<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** B4: one release of allocated fulfilments into pick tasks. */
class Wave extends Model
{
    protected $fillable = ['wave_no', 'warehouse_id', 'status', 'released_by', 'released_at', 'notes'];

    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WarehouseTask::class);
    }
}
