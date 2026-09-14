<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Optional child of an ASN, basic fields only — no container lifecycle (ERP_PLAN §0.2 rule 1). A row may point at the
 * physical box it shares with other ASNs' rows (拼柜 / LCL, CHANGE_REQUESTS #122); the devanning_* columns snapshot the
 * member's last share of that box's devanning / cartage fee.
 */
class Container extends Model
{
    protected $fillable = [
        'asn_id', 'job_id', 'physical_container_id', 'container_no', 'size', 'unpack_mode', 'gross_weight_kg', 'line_count',
        'devanning_basis', 'devanning_basis_qty', 'devanning_share', 'devanning_basis_provisional',
    ];

    protected function casts(): array
    {
        return ['gross_weight_kg' => 'decimal:3', 'line_count' => 'integer', 'devanning_basis_qty' => 'decimal:3', 'devanning_share' => 'decimal:4', 'devanning_basis_provisional' => 'boolean'];
    }

    public function asn(): BelongsTo
    {
        return $this->belongsTo(Asn::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AsnLine::class);
    }

    /** The shared physical box this row is linked to, if any (#122). */
    public function physicalContainer(): BelongsTo
    {
        return $this->belongsTo(PhysicalContainer::class);
    }

    public function isLinked(): bool
    {
        return $this->physical_container_id !== null;
    }
}
