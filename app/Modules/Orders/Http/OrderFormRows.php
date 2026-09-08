<?php

namespace App\Modules\Orders\Http;

use Illuminate\Http\Request;

/**
 * The order forms (staff orders::form, portal::orders.create) render spare goods-line and declared-package rows whose
 * package type is a <select> with a default, so an untouched row still submits a value. Drop those rows before
 * validation: a row counts only when something besides the package type / default carton qty was entered.
 */
final class OrderFormRows
{
    private const LINE_CONTENT = ['description_cn', 'description_en', 'unit_qty', 'actual_weight_kg', 'length_mm', 'width_mm', 'height_mm', 'cbm'];

    private const PACKAGE_CONTENT = ['qty', 'weight_kg', 'length_mm', 'width_mm', 'height_mm'];

    public static function prune(Request $request): void
    {
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
