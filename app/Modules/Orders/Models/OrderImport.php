<?php

namespace App\Modules\Orders\Models;

use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;

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
}
