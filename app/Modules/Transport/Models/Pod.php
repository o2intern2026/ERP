<?php

namespace App\Modules\Transport\Models;

use App\Models\User;
use App\Modules\Platform\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pod extends Model
{
    protected $fillable = [
        'shipment_id', 'delivered_at', 'recipient_name', 'signature_document_id',
        'photo_document_ids', 'pod_document_id', 'failure_reason', 'captured_by',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'immutable_datetime',
            'photo_document_ids' => 'array',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function signatureDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'signature_document_id');
    }

    public function podDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'pod_document_id');
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }
}
