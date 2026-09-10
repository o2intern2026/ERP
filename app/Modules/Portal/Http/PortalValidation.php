<?php

namespace App\Modules\Portal\Http;

use App\Modules\Orders\Http\OrderValidation;
use Illuminate\Support\Facades\Lang;

/**
 * 2026-09-10 audit: the portal forms are Chinese-only and reuse the Orders row partials, so every Portal validator passes
 * the Orders row-aware messages / attribute names (`第 2 行货物的箱数必填。`) plus the portal-specific ones from
 * lang/zh/portal.php (`validation.messages` / `validation.attributes`, which win on a duplicate key).
 */
final class PortalValidation
{
    /** @return array<string, string> */
    public static function messages(): array
    {
        $own = Lang::get('portal.validation.messages');

        return array_replace(OrderValidation::messages(), is_array($own) ? $own : []);
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        $own = Lang::get('portal.validation.attributes');

        return array_replace(OrderValidation::attributes(), is_array($own) ? $own : []);
    }
}
