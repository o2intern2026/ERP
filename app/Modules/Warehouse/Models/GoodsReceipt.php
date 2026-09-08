<?php

namespace App\Modules\Warehouse\Models;

use App\Models\User;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Document;
use App\Modules\Platform\Models\Job;
use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * 入库单 (goods receipt) = one receiving batch of an ASN (预报单). receipt_no = {asn_no}-R{batch_no}; a batch is `open`
 * while lines are being received and `completed` once the operator confirms 入库完成 (totals snapshotted, PDF attached).
 */
class GoodsReceipt extends Model
{
    use BelongsToClient;
    use LogsActivity;

    protected $fillable = [
        'receipt_no', 'asn_id', 'client_id', 'warehouse_id', 'job_id', 'batch_no', 'status', 'unplanned', 'delivery_reference',
        'opened_at', 'opened_by', 'completed_at', 'completed_by', 'expected_cartons', 'received_cartons', 'damaged_cartons',
        'variance_cartons', 'line_count', 'unit_count', 'pallet_count', 'pdf_document_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'batch_no' => 'integer', 'unplanned' => 'boolean', 'opened_at' => 'datetime', 'completed_at' => 'datetime',
            'expected_cartons' => 'integer', 'received_cartons' => 'integer', 'damaged_cartons' => 'integer', 'variance_cartons' => 'integer',
            'line_count' => 'integer', 'unit_count' => 'integer', 'pallet_count' => 'integer',
        ];
    }

    public function asn(): BelongsTo
    {
        return $this->belongsTo(Asn::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class)->orderBy('id');
    }

    public function stockUnits(): HasMany
    {
        return $this->hasMany(StockUnit::class);
    }

    public function pdfDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'pdf_document_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'completed_at', 'completed_by', 'pdf_document_id'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
