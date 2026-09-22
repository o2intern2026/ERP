<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Modules\Platform\Http\Controllers\LoginController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** CR #137 (audit ADMIN-13): login throttling, a 修改密码 page for every signed-in user, no admin lock-out through /admin/users. */
class AccountSecurityTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_login_is_throttled_after_five_wrong_attempts_per_email_and_ip(): void
    {
        $user = $this->staff('finance');
        RateLimiter::clear(LoginController::throttleKey($user->email, '127.0.0.1'));

        for ($i = 1; $i <= 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-'.$i])->assertSessionHasErrors(['email' => __('platform.auth.failed')]);
        }
        // The sixth try is refused in Chinese even with the right password; another email from the same IP is not affected.
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $response->assertSessionHasErrors('email');
        $this->assertStringStartsWith('登录尝试次数过多', session('errors')->first('email'));
        $this->assertGuest();
        $other = $this->staff('dispatcher');
        $this->post('/login', ['email' => $other->email, 'password' => 'password'])->assertRedirect('/jobs');
        $this->assertAuthenticatedAs($other);

        // Once the minute is over the lock lifts, and a good login clears the counter.
        auth()->logout();
        RateLimiter::clear(LoginController::throttleKey($user->email, '127.0.0.1'));
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/jobs');
        $this->assertSame(0, RateLimiter::attempts(LoginController::throttleKey($user->email, '127.0.0.1')));
    }

    public function test_every_signed_in_user_changes_their_own_password(): void
    {
        $staff = $this->staff('warehouse_operator');
        $clientUser = $this->clientUser();

        // Linked from the nav (the user name), for staff and client users alike; the client user is admitted outside /portal for this page only.
        $this->actingAs($staff)->get('/jobs')->assertOk()->assertSee(route('platform.password.edit'));
        $this->actingAs($clientUser)->get('/portal')->assertOk()->assertSee(route('platform.password.edit'));
        $this->actingAs($staff)->get('/account/password')->assertOk()->assertSee(__('platform.password.title'));
        $this->actingAs($clientUser)->get('/account/password')->assertOk()->assertSee(__('platform.password.title'));
        $this->actingAs($clientUser)->get('/jobs')->assertForbidden();

        // Wrong current password, too short, or mismatched confirmation: refused, nothing changes.
        $this->actingAs($staff)->put('/account/password', ['current_password' => 'nope', 'password' => 'new-secret-9', 'password_confirmation' => 'new-secret-9'])->assertSessionHasErrors('current_password');
        $this->actingAs($staff)->put('/account/password', ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->actingAs($staff)->put('/account/password', ['current_password' => 'password', 'password' => 'new-secret-9', 'password_confirmation' => 'other-secret'])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', $staff->fresh()->password));

        $this->actingAs($staff)->put('/account/password', ['current_password' => 'password', 'password' => 'new-secret-9', 'password_confirmation' => 'new-secret-9'])
            ->assertRedirect('/account/password')->assertSessionHas('status', __('platform.password.saved'));
        $this->assertTrue(Hash::check('new-secret-9', $staff->fresh()->password));
        auth()->logout();
        $this->post('/login', ['email' => $staff->email, 'password' => 'new-secret-9'])->assertRedirect('/jobs');
    }

    public function test_an_admin_cannot_deactivate_or_demote_themselves_or_the_last_active_admin(): void
    {
        $admin = $this->staff();
        $payload = fn (User $u, array $over = []) => $over + ['name' => $u->name, 'email' => $u->email, 'role' => 'admin', 'is_active' => 1];

        // Yourself: neither 停用 nor a different role.
        $this->actingAs($admin)->put("/admin/users/{$admin->id}", $payload($admin, ['is_active' => 0]))->assertSessionHasErrors(['is_active' => __('platform.users.errors.self_lockout')]);
        $this->actingAs($admin)->put("/admin/users/{$admin->id}", $payload($admin, ['role' => 'finance']))->assertSessionHasErrors(['is_active' => __('platform.users.errors.self_lockout')]);
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($admin->fresh()->hasRole('admin'));

        // The last active admin (edited by a second, inactive admin's session would be odd — so: a second admin edits the first while being the only other, inactive one).
        $second = $this->staff('admin', ['is_active' => false]);
        $this->actingAs($admin)->put("/admin/users/{$second->id}", $payload($second, ['is_active' => 0, 'role' => 'finance']))->assertRedirect('/admin/users'); // an inactive admin may be demoted
        $this->assertTrue($second->fresh()->hasRole('finance'));

        $third = $this->staff('admin'); // now two active admins
        $this->actingAs($third)->put("/admin/users/{$admin->id}", $payload($admin, ['is_active' => 0]))->assertRedirect('/admin/users');
        $this->assertFalse($admin->fresh()->is_active);
        // $third is the last active admin: nobody may deactivate or demote them — not even another (inactive) admin's session.
        $this->actingAs($third)->put("/admin/users/{$third->id}", $payload($third, ['is_active' => 0]))->assertSessionHasErrors('is_active');
        $admin->forceFill(['is_active' => true])->save();
        $admin->syncRoles(['admin']);
        $this->actingAs($third)->put("/admin/users/{$admin->id}", $payload($admin, ['is_active' => 0]))->assertRedirect('/admin/users'); // two active admins → fine
        $this->actingAs($admin)->put("/admin/users/{$third->id}", $payload($third, ['role' => 'dispatcher']))->assertSessionHasErrors(['is_active' => __('platform.users.errors.last_admin')]);
        $this->assertTrue($third->fresh()->hasRole('admin'));
        $this->actingAs($admin)->put("/admin/users/{$third->id}", $payload($third, ['is_active' => 0]))->assertSessionHasErrors(['is_active' => __('platform.users.errors.last_admin')]);
        $this->assertTrue($third->fresh()->is_active);
    }
}
