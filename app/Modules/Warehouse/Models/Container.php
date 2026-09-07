<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Optional child of an ASN, basic fields only — no container lifecycle (ERP_PLAN §0.2 rule 1). */
class Container extends Model
{
    protected $fillable = ['asn_id', 'job_id', 'container_no', 'size', 'unpack_mode', 'gross_weight_kg', 'line_count'];

    protected function casts(): array
    {
        return ['gross_weight_kg' => 'decimal:3', 'line_count' => 'integer'];
    }

    public function asn(): BelongsTo
    {
        return $this->belongsTo(Asn::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AsnLine::class);
    }
}
