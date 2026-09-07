<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerQuoteLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['customer_quote_id', 'charge_code', 'description', 'qty', 'uom', 'amount_cents', 'transport_quote_id', 'assumptions'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'amount_cents' => 'integer', 'assumptions' => 'array'];
    }
}
