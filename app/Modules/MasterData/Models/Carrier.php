<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Carrier master data only; transport attributes are carrier_services (Transport module, X2). */
class Carrier extends Model
{
    use LogsActivity;

    protected $fillable = ['code', 'name', 'abn', 'contact_name', 'contact_phone', 'contact_email', 'status'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
