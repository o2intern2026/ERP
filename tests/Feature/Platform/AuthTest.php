<?php

namespace Tests\Feature\Platform;

use App\Modules\MasterData\Models\Client;
use App\Support\Tenancy\ClientScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** A1: login, inactive users, client confinement, global client scope. */
class AuthTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee(__('platform.auth.login'));
    }

    public function test_guests_are_redirected_to_login_everywhere(): void
    {
        $this->get('/jobs')->assertRedirect('/login');
        $this->get('/warehouse')->assertRedirect('/login');
        $this->get('/admin/clients')->assertRedirect('/login');
    }

    public function test_staff_log_in_and_land_on_the_job_workbench(): void
    {
        $user = $this->staff('dispatcher');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/jobs');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_and_inactive_users_are_rejected(): void
    {
        $user = $this->staff('finance');
        $this->post('/login', ['email' => $user->email, 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $inactive = $this->staff('finance', ['is_active' => false]);
        $this->post('/login', ['email' => $inactive->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_client_users_land_on_the_portal_and_cannot_leave_it(): void
    {
        $user = $this->clientUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/portal');
        $this->actingAs($user)->get('/portal')->assertOk();
        $this->actingAs($user)->get('/jobs')->assertForbidden();
        $this->actingAs($user)->get('/warehouse')->assertForbidden();
        $this->actingAs($user)->get('/admin/clients')->assertForbidden();
    }

    public function test_a_client_user_without_a_client_is_refused(): void
    {
        $user = $this->clientUser();
        $user->forceFill(['client_id' => null])->save();

        $this->actingAs($user)->get('/portal')->assertForbidden();
    }

    public function test_logout(): void
    {
        $this->actingAs($this->staff())->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_global_client_scope_limits_rows_to_the_current_client(): void
    {
        $a = $this->client();
        $this->client();

        ClientScope::set($a->id);
        $this->assertSame([$a->id], Client::query()->pluck('id')->all());

        ClientScope::set(null);
        $this->assertCount(2, Client::all());
    }
}
