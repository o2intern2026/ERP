<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\MasterData\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A4b: a client's API credential. Only the SHA-256 hash is stored; the plain token is shown once when issued. */
class OrderApiToken extends Model
{
    protected $fillable = ['client_id', 'name', 'token_hash', 'last_used_at', 'revoked_at', 'created_by'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
