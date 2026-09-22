<?php

namespace App\Support\Tenancy;

use App\Modules\MasterData\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global client scope — multi-tenancy lives in the data layer, not in page checks (ERP_PLAN §0.2, AGENTS.md).
 *
 * For a client-role user the middleware sets the current client id (their users.client_id); every model using
 * BelongsToClient then only sees rows with that client_id, and the request may only reach /portal/** and /logout
 * (contracts/routes.md) plus /account/** (their own password, CR #137). Staff users have no current client and see
 * everything. Cost / margin fields are removed for client users by the services that produce them (e.g. JobService::summarize).
 * CR #137 (audit GAP-03): a client user whose client is no longer `active` is signed out with a message on the next request —
 * deactivating the client on /admin/clients closes the portal at once, whatever the session or users.is_active says.
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

        if (Client::query()->withoutGlobalScopes()->whereKey($user->client_id)->value('status') !== 'active') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('platform.login')->withErrors(['email' => __('platform.auth.inactive')]);
        }

        self::set((int) $user->client_id);

        abort_unless($request->is('portal', 'portal/*', 'logout', 'account/*'), 403);

        return $next($request);
    }

    public function terminate(): void
    {
        self::set(null);
    }
}
