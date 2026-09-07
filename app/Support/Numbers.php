<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/** PREFIX-<date>-NNNN document numbers, sequence per period under a row lock (call inside a transaction). */
final class Numbers
{
    public static function next(Builder $query, string $column, string $prefix, string $period = 'Ymd'): string
    {
        $stem = $prefix.'-'.now()->format($period).'-';
        $last = $query->where($column, 'like', $stem.'%')->lockForUpdate()->orderByDesc($column)->value($column);
        $sequence = $last ? ((int) substr((string) $last, -4)) + 1 : 1;

        return $stem.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
