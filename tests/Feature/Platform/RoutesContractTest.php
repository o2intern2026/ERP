<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Enforces contracts/routes.md: every registered route is named "<module>.…" and its URI starts with one of
 * that module's URL prefixes. Framework routes (health check, local storage) are skipped.
 */
class RoutesContractTest extends TestCase
{
    /** @var array<string, list<string>> module → URL prefixes (contracts/routes.md) */
    private const PREFIXES = [
        'platform' => ['/', 'login', 'logout', 'register', 'admin', 'jobs'],
        'masterdata' => ['admin/clients', 'admin/suppliers', 'admin/carriers'],
        'warehouse' => ['warehouse'],
        'billing' => ['billing'],
        'orders' => ['orders'],
        'portal' => ['portal'],
        'reports' => ['reports'],
        'transport' => ['transport', 'driver'],
    ];

    private const FRAMEWORK_URIS = ['up', 'storage/{path}'];

    /** @var array<string, string> route name → URI (contracts/routes.md "M0 placeholders") */
    private const PLACEHOLDERS = [
        'platform.index' => 'jobs',
        'masterdata.index' => 'admin/clients',
        'warehouse.index' => 'warehouse',
        'billing.index' => 'billing',
        'orders.index' => 'orders',
        'portal.index' => 'portal',
        'reports.index' => 'reports',
        'transport.index' => 'transport',
        'transport.driver' => 'driver',
    ];

    public function test_every_route_obeys_the_module_prefix_table(): void
    {
        $violations = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (in_array($uri, self::FRAMEWORK_URIS, true)) {
                continue;
            }

            $name = $route->getName();
            if ($name === null) {
                $violations[] = "unnamed route: {$uri}";

                continue;
            }

            $module = Str::before($name, '.');
            $prefixes = self::PREFIXES[$module] ?? null;
            if ($prefixes === null) {
                $violations[] = "{$name}: unknown module prefix '{$module}'";

                continue;
            }

            $inside = collect($prefixes)->contains(
                fn (string $p) => $p === '/' ? $uri === '/' : ($uri === $p || Str::startsWith($uri, $p.'/'))
            );
            if (! $inside) {
                $violations[] = "{$name}: uri '{$uri}' is outside ".implode(', ', $prefixes);
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_every_module_has_its_placeholder_route(): void
    {
        foreach (self::PLACEHOLDERS as $name => $uri) {
            $this->assertTrue(Route::has($name), "missing route {$name}");
            $this->assertSame(url($uri), route($name));
        }
    }

    public function test_root_redirects_to_the_job_workbench(): void
    {
        $this->get('/')->assertRedirect('/jobs');
    }
}
