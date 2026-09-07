<?php

namespace App\Modules\Warehouse\Services;

use Illuminate\Database\Eloquent\Builder;

/** PREFIX-YYYYMMDD-NNNN, sequence per day under a row lock (call inside a transaction). */
final class DocumentNumbers
{
    public static function next(Builder $query, string $column, string $prefix): string
    {
        $stem = $prefix.'-'.now()->format('Ymd').'-';
        $last = $query->where($column, 'like', $stem.'%')->lockForUpdate()->orderByDesc($column)->value($column);
        $sequence = $last ? ((int) substr((string) $last, -4)) + 1 : 1;

        return $stem.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
