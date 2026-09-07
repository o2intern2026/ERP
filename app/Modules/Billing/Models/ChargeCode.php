<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A24: the fee catalogue (contracts/charge-codes.md). Codes describe what a fee is; rate cards price them. */
class ChargeCode extends Model
{
    protected $fillable = ['code', 'category', 'default_uom', 'customer_description', 'internal_description', 'tax_treatment', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ChargeRule::class);
    }

    public function gstRate(): float
    {
        return $this->tax_treatment === 'gst_10' ? 0.10 : 0.0;
    }
}
