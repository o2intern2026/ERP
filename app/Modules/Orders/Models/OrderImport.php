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
        'client_id', 'source', 'container_no', 'document_id', 'status', 'row_count', 'error_count', 'errors', 'created_by', // container_no: CHANGE_REQUESTS #158
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

    /**
     * Every order this submission stands for once confirmed (CHANGE_REQUESTS #128): the orders it created (`result.created`) and,
     * for a manual portal list, the client's existing orders it attached (`result.attached`) — 待建预报, the collection subset rule and
     * the portal pages all read this, never `result.created` alone.
     *
     * @return list<int>
     */
    public function orderIds(): array
    {
        $result = is_array($this->errors['result'] ?? null) ? $this->errors['result'] : [];

        return array_values(array_unique(array_map('intval', [
            ...array_column($result['created'] ?? [], 'order_id'),
            ...array_column($result['attached'] ?? [], 'order_id'),
        ])));
    }

    /** CHANGE_REQUESTS #144: the order type the list produces — `pickup_deliver` for a 提货直送 list, else `from_stock` (every older import). */
    public function orderType(): string
    {
        return ($this->errors['context']['order_type'] ?? null) === 'pickup_deliver' ? 'pickup_deliver' : 'from_stock';
    }

    /** A portal 手工建立入库清单 (rows typed on the page and / or existing orders ticked), draft, pending or confirmed. */
    public function isManual(): bool
    {
        return is_array($this->errors['context']['manual'] ?? null);
    }

    /**
     * The existing orders a manual list ticked, as posted (draft / pending); after confirm `result.attached` is the record.
     *
     * @return list<int>
     */
    public function manualAttachedIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) ($this->errors['context']['manual']['attached_order_ids'] ?? []))));
    }
}
