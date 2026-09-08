<?php

namespace Tests\Feature\Platform;

use App\Support\Tenancy\ClientScope;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Tests\TestCase;

/** CHANGE_REQUESTS #41: the tenant scope must be set before implicit route-model binding resolves `{order}`-style parameters. */
class ClientScopeBindingTest extends TestCase
{
    public function test_client_scope_runs_before_substitute_bindings(): void
    {
        $priority = app(Kernel::class)->getMiddlewarePriority();
        $scope = array_search(ClientScope::class, $priority, true);
        $bindings = array_search(SubstituteBindings::class, $priority, true);

        $this->assertNotFalse($scope, 'ClientScope must be in the middleware priority list');
        $this->assertNotFalse($bindings);
        $this->assertLessThan($bindings, $scope, 'ClientScope must sort before SubstituteBindings');
    }
}
