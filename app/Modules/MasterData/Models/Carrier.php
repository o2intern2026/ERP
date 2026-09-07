<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

/** Carrier master data only; transport attributes are carrier_services (Transport module, X2). */
class Carrier extends Model
{
    protected $fillable = ['code', 'name', 'abn', 'contact_name', 'contact_phone', 'contact_email', 'status'];
}
