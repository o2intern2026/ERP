<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global client scope — multi-tenancy lives in the data layer, not in page checks (ERP_PLAN §0.2, AGENTS.md).
 *
 * For a client-role user the middleware sets the current client id (their users.client_id); every model using
 * BelongsToClient then only sees rows with that client_id, and the request may only reach /portal/** and /logout
 * (contracts/routes.md). Staff users have no current client and see everything. Cost / margin fields are removed
 * for client users by the services that produce them (e.g. JobService::summarize).
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

    public static function isClientRequest(): bool
    {
        return self::$clientId !== null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isClientUser()) {
            self::set(null);

            return $next($request);
        }

        // A client-role user without a client would otherwise see everything: refuse instead.
        abort_if($user->client_id === null, 403);

        self::set((int) $user->client_id);

        abort_unless($request->is('portal', 'portal/*', 'logout'), 403);

        return $next($request);
    }

    public function terminate(): void
    {
        self::set(null);
    }
}
