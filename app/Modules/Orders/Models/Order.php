<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\OrderEnums;
use App\Modules\Platform\Models\Job;
use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToClient;

    protected $fillable = [
        'order_no', 'client_id', 'job_id', 'order_type', 'source', 'external_ref', 'consignment_mark',
        'fba_reference', 'pickup_address', 'deliver_to_name', 'deliver_to_phone', 'deliver_to_address',
        'deliver_to_suburb', 'deliver_to_state', 'deliver_to_postcode', 'deliver_to_address_type',
        'delivery_instructions', 'requested_date', 'operational_status', 'fulfilment_status', 'billing_status', 'service_level',
        'tailgate_required', 'tailgate_reason', 'customer_quote_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'pickup_address' => 'array',
            'requested_date' => 'date',
            'tailgate_required' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function declaredPackages(): HasMany
    {
        return $this->hasMany(DeclaredPackage::class);
    }

    public function fulfilments(): HasMany
    {
        return $this->hasMany(Fulfilment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->oldest('id');
    }

    public function isEditable(): bool
    {
        return in_array($this->operational_status, ['received', 'confirmed', 'allocated'], true);
    }

    public function customerStatus(): string
    {
        if ($this->billing_status === 'billed') {
            return 'invoiced';
        }

        return OrderEnums::CUSTOMER_STATUS_MAP[$this->operational_status];
    }
}
