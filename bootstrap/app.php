<?php

use App\Support\Tenancy\ClientScope;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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

        $middleware->redirectGuestsTo(fn () => route('platform.login'));
        $middleware->redirectUsersTo(fn () => auth()->user()?->isClientUser() ? route('portal.index') : route('platform.index'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
