<?php

namespace App\Modules\Billing\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * `rate_items.threshold_json` as an array, tolerant of a double-encoded row (audit 2026-09-22 FIN-08, CHANGE_REQUESTS #140): until this
 * fix `RateCardService::newVersion` copied raw attributes through the `array` cast, so every copied version stored the thresholds as a
 * JSON *string* inside JSON and read them back as a string — pick bands, container caps and pallet sizes silently vanished on v2+.
 * Reading decodes twice when the first decode yields a string; writing always stores one level, so a healed row is written back clean.
 *
 * @implements CastsAttributes<array<string, mixed>|null, array<string, mixed>|string|null>
 */
final class ThresholdJson implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }
            $value = is_array($decoded) ? $decoded : null;
        }

        return $value === null ? null : json_encode($value);
    }
}
