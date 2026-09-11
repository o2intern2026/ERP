<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\OrderEnums;
use App\Modules\Platform\Models\Job;
use App\Support\Contracts\ExceptionService;
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
        'tailgate_required', 'tailgate_reason', 'customer_quote_id', 'transport_preference', 'created_by',
        'original_order_id', 'return_inspected_at', 'return_decision', 'return_decided_by', 'return_decided_at', 'return_decision_note',
    ];

    protected function casts(): array
    {
        return [
            'pickup_address' => 'array',
            'transport_preference' => 'array',
            'requested_date' => 'date',
            'tailgate_required' => 'boolean',
            'return_inspected_at' => 'datetime',
            'return_decided_at' => 'datetime',
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

    /** A11: the order this return was raised against. */
    public function originalOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'original_order_id');
    }

    /** A11: return orders raised against this order. */
    public function returnOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'original_order_id')->oldest('id');
    }

    public function returnDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_decided_by');
    }

    /** OMS-4 stage 1: before picking starts coordinators change or cancel freely. */
    public function isEditable(): bool
    {
        return in_array($this->operational_status, ['received', 'confirmed', 'allocated'], true);
    }

    /** OMS-4 stage 2: from picking on, changes need a supervisor / admin and a reason. */
    public function isEditableWithApproval(): bool
    {
        return in_array($this->operational_status, ['picking', 'packed'], true);
    }

    /** OMS-4 stage 3 / §3.8 #4: shipped orders cannot be edited or cancelled — only a return remains. */
    public function isShipped(): bool
    {
        return in_array($this->operational_status, ['dispatched', 'delivered'], true);
    }

    /** A return may be requested against a shipped, non-return order (OMS-9). */
    public function acceptsReturnRequest(): bool
    {
        return $this->order_type !== 'return' && $this->isShipped();
    }

    /** A13 / OMS-11: exposed for Transport / Warehouse — an active financial hold (order or client-wide) blocks booking and dispatch. */
    public function hasActiveFinancialHold(): bool
    {
        return app(ExceptionService::class)->hasActiveHold('financial', $this->client_id, $this->id);
    }

    public function customerStatus(): string
    {
        if ($this->billing_status === 'billed') {
            return 'invoiced';
        }

        return OrderEnums::CUSTOMER_STATUS_MAP[$this->operational_status];
    }
}
