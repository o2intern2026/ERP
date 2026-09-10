<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_json_requests_still_get_a_plain_403(): void
    {
        $this->actingAs($this->staff('warehouse_operator'))->getJson('/admin/users')->assertForbidden()->assertJsonStructure(['message']);
    }
}
