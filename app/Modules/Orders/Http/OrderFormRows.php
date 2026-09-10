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
}
