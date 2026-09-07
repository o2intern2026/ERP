<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;

class AsnImport extends Model
{
    protected $fillable = ['asn_id', 'client_id', 'job_id', 'document_id', 'status', 'row_count', 'error_count', 'errors', 'warnings', 'created_by'];

    protected function casts(): array
    {
        return ['errors' => 'array', 'warnings' => 'array'];
    }
}
