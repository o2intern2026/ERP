<?php

namespace Tests\Feature\Platform;

use App\Support\Auth\RequiredRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** Tester feedback 2026-09-10: a user without the role sees a Chinese "no permission" page, not a bare 403. */
class ForbiddenPageTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_staff_without_the_role_see_the_no_permission_page_with_their_role_and_the_required_one(): void
    {
        $operator = $this->staff('warehouse_operator');

        $this->actingAs($operator)->get('/admin/users')->assertForbidden()
            ->assertSee(__('platform.errors.forbidden.title'))
            ->assertSee(__('platform.roles.warehouse_operator'))
            ->assertSee(__('platform.roles.admin'))
            ->assertSee(route('platform.index'))
            ->assertDontSee('User does not have the right roles');
    }

    public function test_client_users_outside_the_portal_are_pointed_back_to_the_portal(): void
    {
        $this->actingAs($this->clientUser())->get('/warehouse')->assertForbidden()
            ->assertSee(__('platform.errors.forbidden.title'))
            ->assertSee(__('platform.errors.forbidden.client_hint'))
            ->assertSee(route('portal.index'));
    }

    public function test_code_level_role_checks_name_the_allowed_roles_and_custom_reasons_are_shown(): void
    {
        Route::middleware(['web', 'auth'])->get('/_test/finance-only', function () {
            RequiredRoles::requireAny(['admin', 'finance']);

            return 'ok';
        });
        Route::middleware(['web', 'auth'])->get('/_test/locked', fn () => abort(403, '订单已发运,不能再改'));

        $operator = $this->staff('warehouse_operator');
        $this->actingAs($operator)->get('/_test/finance-only')->assertForbidden()
            ->assertSee(__('platform.errors.forbidden.required', ['roles' => __('platform.roles.admin').' / '.__('platform.roles.finance')]));
        $this->actingAs($this->staff('finance'))->get('/_test/finance-only')->assertOk();
        $this->actingAs($operator)->get('/_test/locked')->assertForbidden()->assertSee('订单已发运,不能再改');

        // A real code-level check: API tokens are admin-only inside the controller.
        $this->actingAs($this->staff('customer_service'))->get('/orders/api-tokens')->assertForbidden()->assertSee(__('platform.roles.admin'));
    }

    public function test_json_requests_still_get_a_plain_403(): void
    {
        $this->actingAs($this->staff('warehouse_operator'))->getJson('/admin/users')->assertForbidden()->assertJsonStructure(['message']);
    }
}
