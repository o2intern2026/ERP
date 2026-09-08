<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Modules\MasterData\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** Tester feedback #8: client self-registration → pending → staff approval → own portal only. */
class RegistrationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_login_page_links_to_registration_and_the_form_renders(): void
    {
        $this->get(route('platform.login'))->assertOk()->assertSee(route('platform.register'));
        $this->get(route('platform.register'))->assertOk()->assertSee(__('platform.auth.company_name'))->assertSee(__('platform.auth.password_confirmation'));
    }

    public function test_a_company_registers_waits_for_approval_and_then_signs_in_to_its_own_portal(): void
    {
        $cs = $this->staff('customer_service'); // also seeds the roles (incl. `client`)

        $this->post(route('platform.register.store'), $this->payload())->assertSessionHasNoErrors()->assertRedirect(route('platform.login'));
        $client = Client::query()->withoutGlobalScopes()->where('name', 'Sunrise Trading Pty Ltd')->sole();
        $user = User::query()->withoutGlobalScopes()->where('email', 'li@sunrise.example')->sole();
        $this->assertSame(['pending', 'SUNRISETRADI', '12 345 678 901', 'prepaid', 'Li Wei'], [$client->status, $client->code, $client->abn, $client->payment_terms, $client->contact_name]);
        $this->assertSame([$client->id, false, true], [$user->client_id, $user->is_active, $user->hasRole('client')]);
        $this->get(route('platform.login'))->assertSee(__('platform.auth.registered'));

        // Not approved yet: the right password is refused with the "pending" message, not "wrong password".
        $this->post(route('platform.login.store'), ['email' => 'li@sunrise.example', 'password' => 'Sunrise-2026!'])->assertSessionHasErrors(['email' => __('platform.auth.pending')]);
        $this->assertGuest();
        $this->post(route('platform.login.store'), ['email' => 'li@sunrise.example', 'password' => 'wrong'])->assertSessionHasErrors(['email' => __('platform.auth.failed')]);

        // Customer service sees the pending client first with an approve button, and approves it.
        $this->actingAs($cs)->get(route('masterdata.index'))->assertOk()
            ->assertSee(__('masterdata.statuses.pending'))->assertSee(route('masterdata.clients.approve', $client))->assertSee(__('masterdata.clients.pending_hint', ['count' => 1]));
        $this->actingAs($cs)->post(route('masterdata.clients.approve', $client))->assertRedirect(route('masterdata.index'));
        $this->assertSame(['active', true], [$client->fresh()->status, $user->fresh()->is_active]);
        $this->post(route('platform.logout'));
        $this->app['auth']->forgetGuards();

        // Approved: signs in and lands on its own portal (the ClientScope confines it to its own rows — AuthTest / PortalOrdersTest).
        $this->post(route('platform.login.store'), ['email' => 'li@sunrise.example', 'password' => 'Sunrise-2026!'])->assertRedirect(route('portal.index'));
        $this->assertAuthenticatedAs($user->fresh());
        $this->get(route('portal.index'))->assertOk();
    }

    public function test_duplicate_emails_same_company_names_weak_passwords_and_closed_signup(): void
    {
        $this->staff();
        $this->post(route('platform.register.store'), $this->payload())->assertSessionHasNoErrors();
        $this->post(route('platform.register.store'), $this->payload())->assertSessionHasErrors('email');
        $this->post(route('platform.register.store'), $this->payload(['email' => 'ops@sunrise.example']))->assertSessionHasNoErrors();
        $this->assertSame(['SUNRISETRADI', 'SUNRISETRADI-2'], Client::query()->withoutGlobalScopes()->where('name', 'Sunrise Trading Pty Ltd')->orderBy('id')->pluck('code')->all());
        $this->post(route('platform.register.store'), $this->payload(['email' => 'x@y.example', 'password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');
        $this->post(route('platform.register.store'), $this->payload(['email' => 'x@y.example', 'password_confirmation' => 'Different-1']))->assertSessionHasErrors('password');

        // A client with no rate card at all still registers (estimates show POA until finance assigns rates).
        $this->assertSame(2, User::query()->withoutGlobalScopes()->where('is_active', false)->count());

        config(['erp.allow_signup' => false]);
        $this->get(route('platform.register'))->assertNotFound();
        $this->post(route('platform.register.store'), $this->payload(['email' => 'closed@sunrise.example']))->assertNotFound();
        $this->get(route('platform.login'))->assertOk()->assertDontSee(route('platform.register'));
    }

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'company_name' => 'Sunrise Trading Pty Ltd', 'abn' => '12 345 678 901', 'contact_name' => 'Li Wei', 'contact_phone' => '0400 000 000',
            'address' => '5 Harbour St', 'suburb' => 'Sydney', 'state' => 'NSW', 'postcode' => '2000',
            'email' => 'Li@Sunrise.example', 'password' => 'Sunrise-2026!', 'password_confirmation' => 'Sunrise-2026!',
        ];
    }
}
