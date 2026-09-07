<?php

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeclaredPackage extends Model
{
    protected $fillable = ['order_id', 'package_type', 'qty', 'weight_kg', 'length_mm', 'width_mm', 'height_mm'];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'weight_kg' => 'decimal:3',
            'length_mm' => 'integer',
            'width_mm' => 'integer',
            'height_mm' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
