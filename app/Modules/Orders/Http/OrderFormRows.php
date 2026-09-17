<?php

namespace App\Modules\Orders\Http;

use Illuminate\Http\Request;

/**
 * The order forms (staff orders::form, portal::orders.create) render spare goods-line and declared-package rows whose
 * package type is a <select> with a default, so an untouched row still submits a value. Drop those rows before
 * validation: a row counts only when something besides the package type was entered (2026-09-10 audit: a typed 箱数 IS
 * content — the row must reach validation and fail on the missing 品名 instead of vanishing; the form no longer pre-fills 1).
 * Declared packages belong to pure transport orders only: the 提货 fieldset is hidden for every other type, so its
 * leftovers are dropped rather than validated against fields the person cannot see.
 */
final class OrderFormRows
{
    /** Deliberately without `storage_tier` (CHANGE_REQUESTS #129): like the package type it is a <select> that always submits, so a tier alone is not content. */
    private const LINE_CONTENT = ['description_cn', 'description_en', 'carton_qty', 'unit_qty', 'actual_weight_kg', 'length_mm', 'width_mm', 'height_mm', 'cbm'];

    private const PACKAGE_CONTENT = ['qty', 'weight_kg', 'length_mm', 'width_mm', 'height_mm'];

    public static function prune(Request $request): void
    {
        if ($request->filled('order_type') && $request->input('order_type') !== 'pickup_deliver' && $request->has('declared_packages')) {
            $request->merge(['declared_packages' => null]);
        }

        foreach (['lines' => self::LINE_CONTENT, 'declared_packages' => self::PACKAGE_CONTENT] as $key => $fields) {
            $rows = $request->input($key);
            if (! is_array($rows)) {
                continue;
            }

            $kept = array_values(array_filter($rows, fn ($row) => is_array($row) && collect($fields)->contains(fn (string $field) => filled($row[$field] ?? null))));
            $request->merge([$key => $kept === [] ? null : $kept]);
        }
    }

    /**
     * CHANGE_REQUESTS #129: who declared each line's storage tier. A validated line that carries a tier gets `$source` (`client` from the
     * portal form / API, `staff` from the manual form); a line without one carries neither key, so the ASN default (standard, no source)
     * applies when the goods are handed to Warehouse. The tier is a preference for the putaway check — never a location.
     *
     * @param  array<string, mixed>  $data  the validated form / API body
     * @return array<string, mixed>
     */
    public static function withStorageTierSource(array $data, string $source): array
    {
        if (! is_array($data['lines'] ?? null)) {
            return $data;
        }

        foreach ($data['lines'] as $index => $line) {
            if (filled($line['storage_tier'] ?? null)) {
                $data['lines'][$index]['storage_tier_source'] = $source;
            } else {
                unset($data['lines'][$index]['storage_tier'], $data['lines'][$index]['storage_tier_source']);
            }
        }

        return $data;
    }
}
