<?php

namespace App\Modules\Transport\Http;

use Illuminate\Support\Facades\Lang;

/**
 * 2026-09-10 audit: the Transport forms are Chinese-only, so every validator in the module passes these as the custom
 * messages / attribute names (`$request->validate($rules, messages(), attributes())`). Array rows use Laravel's wildcard
 * form (`photos.*`, `positions.*`) with `:position`, so a rejected row is never named by its raw dotted key. The arrays
 * live in lang/zh/transport.php under `validation.attributes` / `validation.messages`.
 */
final class TransportValidation
{
    /** @return array<string, string> */
    public static function messages(): array
    {
        $messages = Lang::get('transport.validation.messages');

        return is_array($messages) ? $messages : [];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        $attributes = Lang::get('transport.validation.attributes');

        return is_array($attributes) ? $attributes : [];
    }
}
