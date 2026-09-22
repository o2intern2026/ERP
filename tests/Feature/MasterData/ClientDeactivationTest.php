<?php

namespace Tests\Feature\MasterData;

use App\Modules\MasterData\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** CR #137 (audit GAP-03): 停用 a client closes its portal — logins off, sessions ended, and the refusal says so. */
class ClientDeactivationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** @return array<string, string> */
    private function form(Client $client, string $status): array
    {
        return ['code' => $client->code, 'name' => $client->name, 'leg_type' => 'both', 'status' => $status, 'payment_terms' => 'eom', 'invoice_mode' => 'per_job', 'default_markup_percent' => '20'];
    }

    public function test_deactivating_a_client_deactivates_its_users_and_reactivating_restores_them(): void
    {
        $admin = $this->staff();
        $client = $this->client(['name' => 'Gone Pty Ltd']);
        $a = $this->clientUser($client);
        $b = $this->clientUser($client);
        $otherClientsUser = $this->clientUser();

        $this->actingAs($admin)->get("/admin/clients/{$client->id}/edit")->assertOk()->assertSee(__('masterdata.clients.status_hint'));

        $this->actingAs($admin)->put("/admin/clients/{$client->id}", $this->form($client, 'inactive'))
            ->assertRedirect('/admin/clients')->assertSessionHas('status', __('masterdata.clients.deactivated', ['name' => 'Gone Pty Ltd']));
        $this->assertSame('inactive', $client->fresh()->status);
        $this->assertFalse($a->fresh()->is_active);
        $this->assertFalse($b->fresh()->is_active);
        $this->assertTrue($otherClientsUser->fresh()->is_active);

        // A deactivated login is told so — not "wrong password".
        auth()->logout();
        $this->post('/login', ['email' => $a->email, 'password' => 'password'])->assertSessionHasErrors(['email' => __('platform.auth.inactive')]);
        $this->assertGuest();
        $this->post('/login', ['email' => $a->email, 'password' => 'wrong'])->assertSessionHasErrors(['email' => __('platform.auth.failed')]);

        // Re-activating the client turns the logins back on (symmetrical to approve).
        $this->actingAs($admin)->put("/admin/clients/{$client->id}", $this->form($client, 'active'))->assertRedirect('/admin/clients')->assertSessionHas('status', __('masterdata.saved'));
        $this->assertTrue($a->fresh()->is_active);
        $this->assertTrue($b->fresh()->is_active);
        auth()->logout();
        $this->post('/login', ['email' => $a->email, 'password' => 'password'])->assertRedirect('/portal');
    }

    public function test_a_user_already_signed_in_is_logged_out_on_the_next_portal_request_and_a_stale_active_login_is_refused(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $this->actingAs($user)->get('/portal')->assertOk();

        // The client goes inactive while the session lives (or its users were left active by an older build).
        $client->update(['status' => 'inactive']);
        $this->actingAs($user)->get('/portal')->assertRedirect('/login')->assertSessionHasErrors(['email' => __('platform.auth.inactive')]);
        $this->assertGuest();

        // users.is_active still true (older data): the login itself refuses on the client's status.
        $this->assertTrue($user->fresh()->is_active);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors(['email' => __('platform.auth.inactive')]);
        $this->assertGuest();

        // A staff login is untouched by client statuses; a plain inactive staff login gets the same 已停用 message.
        $staff = $this->staff('finance', ['is_active' => false]);
        $this->post('/login', ['email' => $staff->email, 'password' => 'password'])->assertSessionHasErrors(['email' => __('platform.auth.inactive')]);
        $this->assertGuest();
    }
}
