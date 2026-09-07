<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class CreditNoteLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['credit_note_id', 'invoice_line_id', 'charge_id', 'description', 'amount_cents', 'gst_cents'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'gst_cents' => 'integer'];
    }
}
