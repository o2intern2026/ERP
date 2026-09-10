<?php

use App\Support\Exceptions\RuleViolation;
use App\Support\Tenancy\ClientScope;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Multi-tenancy is a global client scope in the data layer (ERP_PLAN §0.2); routes/web.php applies it to every module.
        $middleware->alias([
            'client.scope' => ClientScope::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // CHANGE_REQUESTS #41: implicit route-model binding must see the tenant, so the client scope runs before SubstituteBindings.
        $middleware->prependToPriorityList(SubstituteBindings::class, ClientScope::class);

        // Behind a reverse proxy / tunnel (Cloudflare, Nginx) the app sees plain HTTP; trust the forwarded scheme so links and cookies stay HTTPS.
        $middleware->trustProxies(at: '*');

        $middleware->redirectGuestsTo(fn () => route('platform.login'));
        $middleware->redirectUsersTo(fn () => auth()->user()?->isClientUser() ? route('portal.index') : route('platform.index'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // i18n/zh sweep (CHANGE_REQUESTS #107): a business-rule refusal no controller caught must not surface as a 500 page —
        // a form submission goes back to the form with the Chinese message. GET and JSON requests keep Laravel's rendering.
        $exceptions->render(function (RuleViolation $e, Request $request) {
            if ($request->isMethodSafe() || $request->expectsJson()) {
                return null;
            }

            // Never flash credentials back into the session (same list as Handler::$dontFlash).
            return back()->withInput($request->except(['password', 'password_confirmation', 'current_password']))->withErrors(['rule' => $e->userMessage()]);
        });
    })->create();
