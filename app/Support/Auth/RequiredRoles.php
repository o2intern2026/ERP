<?php

namespace App\Support\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Throwable;

/**
 * "Who IS allowed?" for the no-permission page (resources/views/errors/403.blade.php, tester feedback 2026-09-10).
 * Controllers call requireAny() instead of abort_unless(...hasAnyRole..., 403) so the page can list the roles;
 * resolve() also reads the roles off Spatie's role middleware exception and, failing that, off the route's `role:` middleware.
 */
final class RequiredRoles
{
    /** @param  list<string>  $roles */
    public static function requireAny(array $roles, ?string $message = null): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasAnyRole($roles)) {
            throw new RoleRequiredException(array_values($roles), $message ?? '');
        }
    }

    /** @return list<string> role names that would have been allowed; [] when unknown */
    public static function resolve(?Request $request, ?Throwable $e): array
    {
        if ($e instanceof RoleRequiredException) {
            return $e->roles;
        }
        if ($e instanceof UnauthorizedException && $e->getRequiredRoles() !== []) {
            return array_values($e->getRequiredRoles());
        }

        $route = $request?->route();
        if ($route === null) {
            return [];
        }
        $roles = [];
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && (Str::startsWith($middleware, 'role:') || Str::startsWith($middleware, 'role_or_permission:'))) {
                $roles = array_merge($roles, explode('|', Str::after($middleware, ':')));
            }
        }
        $roles = array_values(array_unique(array_filter(array_map('trim', $roles))));
        $user = $request->user();

        // Only meaningful when the user really lacks them — otherwise the 403 came from a deeper check we cannot see.
        return $roles !== [] && ($user === null || ! $user->hasAnyRole($roles)) ? $roles : [];
    }
}
