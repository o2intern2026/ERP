<?php

namespace App\Models;

use App\Modules\MasterData\Models\Client;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/** Owned by Platform (seat C). Eight roles via spatie/laravel-permission (contracts/enums.md §1). */
class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable;
    use LogsActivity;

    protected $fillable = ['name', 'email', 'password', 'client_id', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Client-role users are confined to their client_id by the global ClientScope. */
    public function isClientUser(): bool
    {
        return $this->hasRole('client');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logExcept(['password'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
