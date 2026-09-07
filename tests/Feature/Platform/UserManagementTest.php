<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** A1: user management is admin-only; one role per user; client users must belong to a client. */
class UserManagementTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_admin_lists_users_and_others_are_forbidden(): void
    {
        $admin = $this->staff();
        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee($admin->email);

        $this->actingAs($this->staff('dispatcher'))->get('/admin/users')->assertForbidden();
    }

    public function test_admin_creates_a_client_user(): void
    {
        $client = $this->client();

        $this->actingAs($this->staff())->post('/admin/users', [
            'name' => 'Edward Portal', 'email' => 'portal@edward.test', 'password' => 'secret-123',
            'role' => 'client', 'client_id' => $client->id, 'is_active' => 1,
        ])->assertRedirect('/admin/users');

        $user = User::query()->where('email', 'portal@edward.test')->firstOrFail();
        $this->assertTrue($user->hasRole('client'));
        $this->assertSame($client->id, $user->client_id);
    }

    public function test_client_role_requires_a_client(): void
    {
        $this->actingAs($this->staff())->post('/admin/users', [
            'name' => 'No Client', 'email' => 'x@example.test', 'password' => 'secret-123', 'role' => 'client',
        ])->assertSessionHasErrors('client_id');
    }

    public function test_admin_changes_role_and_deactivates(): void
    {
        $user = $this->staff('warehouse_operator');

        $this->actingAs($this->staff())->put("/admin/users/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'role' => 'finance', 'is_active' => 0,
        ])->assertRedirect('/admin/users');

        $user->refresh();
        $this->assertTrue($user->hasRole('finance'));
        $this->assertFalse($user->hasRole('warehouse_operator'));
        $this->assertFalse($user->is_active);
    }
}
