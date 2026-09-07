<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class WebhookEndpoint extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'url', 'secret', 'events', 'active', 'created_by'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['events' => 'array', 'active' => 'boolean'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    public function wants(string $eventName): bool
    {
        return in_array('*', $this->events, true) || in_array($eventName, $this->events, true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'url', 'events', 'active'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
