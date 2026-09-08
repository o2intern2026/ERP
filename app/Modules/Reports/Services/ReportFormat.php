<?php

namespace App\Modules\Reports\Services;

use App\Support\Money;
use Carbon\Carbon;

/** Cell formatting shared by the Blade tables, the CSV exports and the mail: money is integer cents in, "$1,234.50" / "1234.50" out. */
final class ReportFormat
{
    public const NUMERIC_TYPES = ['int', 'money', 'percent'];

    public static function html(string $type, mixed $value): string
    {
        return match ($type) {
            'money' => $value === null ? '—' : Money::cents((int) $value)->format(),
            'percent' => $value === null ? '—' : number_format((float) $value * 100, 1).'%',
            'int' => $value === null ? '0' : number_format((int) $value),
            'datetime' => $value === null ? '—' : Carbon::parse($value)->format('Y-m-d H:i'),
            default => (string) ($value ?? ''),
        };
    }

    public static function csv(string $type, mixed $value): string
    {
        return match ($type) {
            'money' => $value === null ? '' : Money::cents((int) $value)->toDecimal(),
            'percent' => $value === null ? '' : number_format((float) $value * 100, 1, '.', ''),
            'int' => (string) (int) ($value ?? 0),
            'datetime' => $value === null ? '' : Carbon::parse($value)->format('Y-m-d H:i'),
            default => (string) ($value ?? ''),
        };
    }
}
