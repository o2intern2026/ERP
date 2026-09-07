<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['code', 'name', 'abn', 'contact_name', 'contact_phone', 'contact_email', 'address', 'status'];
}
