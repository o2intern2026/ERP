<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = ['code', 'name', 'address', 'state', 'active', 'business_hours'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'business_hours' => 'array'];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
