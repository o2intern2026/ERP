<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global client scope — multi-tenancy lives in the data layer, not in page checks (ERP_PLAN §0.2, AGENTS.md).
 *
 * M0 skeleton: holds the "current client" for the request and applies nothing.
 * M1/A1 resolves it from the authenticated user (role `client` → that user's client_id; staff → null)
 * and BelongsToClient then constrains every scoped query. Cost / margin fields are hidden for client
 * users server side (resource classes), also in M1.
 */
final class ClientScope
{
    private static ?int $clientId = null;

    public static function currentClientId(): ?int
    {
        return self::$clientId;
    }

    public static function set(?int $clientId): void
    {
        self::$clientId = $clientId;
    }

    public function handle(Request $request, Closure $next): Response
    {
        self::set(null); // M1/A1: derive from $request->user()

        return $next($request);
    }
}
