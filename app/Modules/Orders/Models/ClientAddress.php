<?php

namespace App\Modules\Orders\Models;

use App\Modules\MasterData\Models\Client;
use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAddress extends Model
{
    use BelongsToClient;

    protected $fillable = [
        'client_id', 'label', 'contact_name', 'phone', 'address', 'suburb', 'state', 'postcode',
        'address_type', 'default_instructions', 'usage_count', 'last_used_at',
    ];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'usage_count' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
