<?php

namespace App\Modules\Orders\Http;

use Illuminate\Support\Facades\Lang;

/**
 * 2026-09-10 audit: the Orders forms are Chinese-only, so every validator in the module passes these as the custom
 * messages / attribute names (`$request->validate($rules, messages(), attributes())`). Row-level keys use Laravel's
 * wildcard form (`lines.*.carton_qty`) and `:position` so a rejected array row is named "第 2 行货物…" instead of
 * `lines.1.carton_qty`. The generic per-rule translations are lang/zh/validation.php (seat C, frozen zone).
 */
final class OrderValidation
{
    /** @return array<string, string> */
    public static function messages(): array
    {
        $messages = Lang::get('orders.validation.messages');

        return is_array($messages) ? $messages : [];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        $attributes = Lang::get('orders.validation.attributes');

        return is_array($attributes) ? $attributes : [];
    }
}
