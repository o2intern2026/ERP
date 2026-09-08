<?php

namespace App\Modules\Billing\Models;

use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Job;
use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A8a: draft → issued → part_paid / paid. bill_to_* is a snapshot of the client at issue time (§6.3). */
class Invoice extends Model
{
    use BelongsToClient, LogsActivity;

    protected $fillable = [
        'invoice_no', 'client_id', 'invoice_type', 'group_by', 'period_from', 'period_to', 'bill_to_name', 'bill_to_address', 'bill_to_abn', 'status',
        'issued_at', 'due_at', 'is_overdue', 'paid_at', 'paid_amount_cents', 'subtotal_cents', 'gst_cents', 'total_cents', 'pdf_document_id', 'created_by', 'notes',
    ];

    protected function casts(): array
    {
        return ['period_from' => 'date', 'period_to' => 'date', 'issued_at' => 'datetime', 'due_at' => 'date', 'is_overdue' => 'boolean', 'paid_at' => 'datetime', 'paid_amount_cents' => 'integer', 'subtotal_cents' => 'integer', 'gst_cents' => 'integer', 'total_cents' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function jobs(): BelongsToMany
    {
        return $this->belongsToMany(Job::class, 'invoice_jobs');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function outstandingCents(): int
    {
        return max(0, $this->total_cents - $this->paid_amount_cents - (int) $this->creditNotes()->where('status', 'issued')->sum('amount_cents'));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'issued_at', 'due_at', 'paid_amount_cents', 'total_cents'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
