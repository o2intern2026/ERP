<?php

namespace App\Modules\Reports\Http;

use Illuminate\Support\Facades\Lang;

/**
 * 2026-09-10 audit: the report filter bar is Chinese-only, so the three report controllers (老板视角, staff 客户视角 and the
 * portal twin) pass these as the custom messages / attribute names (`$request->validate($rules, messages(), attributes())`)
 * instead of printing Laravel's English sentence with the raw query key (`to`, `from`, `client_id`). The arrays live in
 * lang/zh/reports.php under `validation.attributes` / `validation.messages`; the generic per-rule file (lang/zh/validation.php)
 * belongs to seat C.
 */
final class ReportValidation
{
    /** @return array<string, string> */
    public static function messages(): array
    {
        $messages = Lang::get('reports.validation.messages');

        return is_array($messages) ? $messages : [];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        $attributes = Lang::get('reports.validation.attributes');

        return is_array($attributes) ? $attributes : [];
    }
}
