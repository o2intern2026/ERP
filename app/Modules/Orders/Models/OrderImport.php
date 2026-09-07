<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\MasterData\Models\Client;
use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderImport extends Model
{
    use BelongsToClient;

    protected $fillable = [
        'client_id', 'source', 'document_id', 'status', 'row_count', 'error_count', 'errors', 'created_by',
    ];

    protected function casts(): array
    {
        return ['errors' => 'array', 'row_count' => 'integer', 'error_count' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
