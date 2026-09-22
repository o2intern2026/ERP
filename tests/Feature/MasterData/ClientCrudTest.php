<?php

namespace Tests\Feature\MasterData;

use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Seeders\BillingSeeder;
use App\Modules\MasterData\Models\Client;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * A2: clients / suppliers / carriers master data with per-client billing settings.
 * CHANGE_REQUESTS #134 (audit A13 / GAP-01): clients are created only by self-registration (/register) — staff approve and
 * edit them — and every code path that creates a Client binds the active standard rate card (Client::creating).
 */
class ClientCrudTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** @return array<string, string> */
    private function validClient(Client $client, array $overrides = []): array
    {
        return $overrides + [
            'code' => $client->code, 'name' => 'Edward Logistics', 'abn' => '12 345 678 901', 'leg_type' => 'both', 'status' => 'active',
            'payment_terms' => 'net_30', 'invoice_mode' => 'per_job', 'default_markup_percent' => '20', 'dispatch_cutoff_time' => '14:00',
            'state' => 'VIC', 'postcode' => '3175',
        ];
    }

    /** @return array<string, mixed> */
    private function bareAttributes(string $code, array $overrides = []): array
    {
        return $overrides + ['code' => $code, 'name' => $code.' Pty Ltd', 'leg_type' => 'both', 'status' => 'active', 'payment_terms' => 'eom', 'invoice_mode' => 'per_job', 'default_markup_percent' => 0];
    }

    public function test_clients_come_from_self_registration_and_staff_approve_and_edit_them(): void
    {
        $admin = $this->staff(); // seeds the roles (incl. `client`)
        $this->seed(BillingSeeder::class);

        // The company registers itself (as a guest): pending client, prepaid terms, standard rate card bound, inactive login.
        $this->post(route('platform.register.store'), [
            'company_name' => 'Edward Logistics', 'abn' => '12 345 678 901', 'contact_name' => 'Ed', 'contact_phone' => '0400 000 000',
            'email' => 'ed@edward.example', 'password' => 'Edward-2026!', 'password_confirmation' => 'Edward-2026!',
        ])->assertSessionHasNoErrors()->assertRedirect(route('platform.login'));
        $client = Client::query()->where('name', 'Edward Logistics')->sole();
        $this->assertSame(['pending', 'prepaid', RateCard::activeStandardId()], [$client->status, $client->payment_terms, $client->standard_rate_card_id]);

        // No staff "new client" form any more (CR #134): the old routes are gone (405 — the URIs survive only as PUT /admin/clients/{client}
        // and GET /admin/clients) and nothing is created.
        $this->actingAs($admin)->get('/admin/clients/create')->assertMethodNotAllowed();
        $this->actingAs($admin)->post('/admin/clients', $this->bareAttributes('EDWARD'))->assertMethodNotAllowed();
        $this->assertSame(0, Client::query()->where('code', 'EDWARD')->count());

        // Staff see it (with the registration hint instead of a create button), approve it and edit its billing behaviour.
        $this->actingAs($admin)->get('/admin/clients')->assertOk()
            ->assertSee('Edward Logistics')->assertSee(__('masterdata.clients.pending_hint', ['count' => 1]))
            ->assertSee(__('masterdata.clients.signup_link'))->assertSee(route('platform.register'))->assertDontSee('/admin/clients/create');
        $this->actingAs($admin)->post(route('masterdata.clients.approve', $client))->assertRedirect('/admin/clients');
        $this->actingAs($admin)->put("/admin/clients/{$client->id}", $this->validClient($client))->assertRedirect('/admin/clients');
        $client->refresh();
        $this->assertSame(['active', 'net_30', '14:00:00'], [$client->status, $client->payment_terms, $client->dispatch_cutoff_time]);

        $this->actingAs($admin)->put("/admin/clients/{$client->id}", $this->validClient($client, ['invoice_mode' => 'monthly', 'payment_terms' => 'eom']))->assertRedirect('/admin/clients');
        $this->assertSame(['monthly', 'eom'], [$client->fresh()->invoice_mode, $client->fresh()->payment_terms]);
        $this->actingAs($admin)->get('/admin/clients')->assertOk()->assertSee(__('masterdata.invoice_modes.monthly'));
    }

    public function test_index_hint_follows_allow_signup(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->get('/admin/clients')->assertOk()
            ->assertSee(__('masterdata.clients.signup_hint'))->assertSee(route('platform.register'))->assertDontSee(__('masterdata.clients.signup_closed_hint'));

        config(['erp.allow_signup' => false]); // /register is a 404 then (RegistrationTest) — the index says so instead of linking it
        $this->actingAs($admin)->get('/admin/clients')->assertOk()
            ->assertSee(__('masterdata.clients.signup_closed_hint'))->assertDontSee(route('platform.register'))->assertDontSee(__('masterdata.clients.signup_link'));
    }

    public function test_payment_terms_must_be_prepaid_eom_or_net_n(): void
    {
        $admin = $this->staff();
        $client = $this->client();

        foreach (['net30', 'cod', 'net_'] as $bad) {
            $this->actingAs($admin)->put("/admin/clients/{$client->id}", $this->validClient($client, ['payment_terms' => $bad]))->assertSessionHasErrors('payment_terms');
        }
        foreach (['prepaid', 'eom', 'net_7', 'net_14', 'net_30'] as $good) {
            $this->actingAs($admin)->put("/admin/clients/{$client->id}", $this->validClient($client, ['payment_terms' => $good]))->assertSessionHasNoErrors();
            $this->assertSame($good, $client->fresh()->payment_terms);
        }
    }

    public function test_every_new_client_is_bound_to_the_active_standard_card_whatever_creates_it(): void
    {
        $this->seed(BillingSeeder::class);
        $standardId = RateCard::activeStandardId();
        $this->assertNotNull($standardId);

        // Plain model creates — field omitted, or passed as null — get the active standard card (the audit gap: no more unpriced clients).
        $omitted = Client::query()->create($this->bareAttributes('OMITTED'));
        $explicitNull = Client::query()->create($this->bareAttributes('NULLED', ['standard_rate_card_id' => null]));
        $this->assertSame([$standardId, $standardId], [$omitted->fresh()->standard_rate_card_id, $explicitNull->fresh()->standard_rate_card_id]);

        // Only the ACTIVE standard version counts (a draft v2 is ignored) and an explicit binding is kept as given.
        $draft = RateCard::query()->create(['client_id' => null, 'name' => 'Standard draft', 'version' => 2, 'effective_from' => today(), 'status' => 'draft', 'is_standard' => true]);
        $this->assertSame($standardId, Client::query()->create($this->bareAttributes('AFTERDRAFT'))->fresh()->standard_rate_card_id);
        $this->assertSame($draft->id, Client::query()->create($this->bareAttributes('EXPLICIT', ['standard_rate_card_id' => $draft->id]))->fresh()->standard_rate_card_id);

        // The shared test helper keeps its contract: an explicit null still yields an unpriced client (Missing Rate tests).
        $this->assertSame($standardId, $this->client()->standard_rate_card_id);
        $this->assertNull($this->client(['standard_rate_card_id' => null])->fresh()->standard_rate_card_id);
    }

    public function test_a_client_created_before_any_standard_card_exists_is_back_filled_by_the_billing_seeder(): void
    {
        $this->assertNull(RateCard::activeStandardId());
        $early = Client::query()->create($this->bareAttributes('EARLY')); // like MasterDataSeeder, which runs before BillingSeeder
        $this->assertNull($early->fresh()->standard_rate_card_id);

        $this->seed(BillingSeeder::class);
        $this->assertNotNull(RateCard::activeStandardId());
        $this->assertSame(RateCard::activeStandardId(), $early->fresh()->standard_rate_card_id);
    }

    public function test_edit_page_shows_the_rate_cards_and_admin_repairs_a_client_without_a_standard_card(): void
    {
        $admin = $this->staff();
        $cs = $this->staff('customer_service');
        $client = $this->client(['name' => 'Cards Pty Ltd']);
        $standard = RateCard::activeStandard();
        $edit = "/admin/clients/{$client->id}/edit";
        $repair = route('masterdata.clients.bind_standard_card', $client);

        // Read-only 标准价目表 name · version and 专属价目表 有 / 无; the card link is for the roles that can open Billing.
        $this->actingAs($cs)->get($edit)->assertOk()
            ->assertSee($standard->name.' · v'.$standard->version)->assertSee(__('masterdata.clients.own_card_no'))
            ->assertDontSee(route('billing.rate_cards.show', $standard))->assertDontSee(__('masterdata.clients.bind_standard_card'));
        $this->actingAs($admin)->get($edit)->assertOk()->assertSee(route('billing.rate_cards.show', $standard));

        RateCard::query()->create(['client_id' => $client->id, 'name' => 'Cards negotiated', 'version' => 3, 'effective_from' => today(), 'status' => 'active']);
        $this->actingAs($cs)->get($edit)->assertOk()->assertSee(__('masterdata.clients.own_card_yes'))->assertSee('Cards negotiated · v3')->assertDontSee(__('masterdata.clients.own_card_no'));

        // Unbound (created before any standard card and the seeder never re-run): warning + 修复 on the edit page and the index, admin only.
        $client->update(['standard_rate_card_id' => null]);
        $this->actingAs($admin)->get($edit)->assertOk()->assertSee(__('masterdata.clients.no_standard_card'))->assertSee($repair);
        $this->actingAs($cs)->get($edit)->assertOk()->assertSee(__('masterdata.clients.no_standard_card'))->assertDontSee($repair);
        $this->actingAs($admin)->get('/admin/clients')->assertOk()->assertSee(__('masterdata.clients.standard_card_missing'))->assertSee($repair);
        $this->actingAs($cs)->get('/admin/clients')->assertOk()->assertSee(__('masterdata.clients.standard_card_missing'))->assertDontSee($repair);
        $this->actingAs($cs)->post($repair)->assertForbidden();
        $this->actingAs($this->staff('finance'))->post($repair)->assertForbidden();
        $this->assertNull($client->fresh()->standard_rate_card_id);

        $this->actingAs($admin)->from($edit)->post($repair)->assertRedirect($edit)->assertSessionHas('status');
        $this->assertSame($standard->id, $client->fresh()->standard_rate_card_id);
        $this->actingAs($admin)->get('/admin/clients')->assertOk()->assertDontSee(__('masterdata.clients.standard_card_missing'))->assertDontSee($repair);
    }

    public function test_repair_refuses_while_no_standard_card_is_active(): void
    {
        $admin = $this->staff();
        $client = Client::query()->create($this->bareAttributes('NOCARD')); // no BillingSeeder → nothing to bind
        $this->assertNull($client->standard_rate_card_id);

        $this->actingAs($admin)->from('/admin/clients')->post(route('masterdata.clients.bind_standard_card', $client))
            ->assertRedirect('/admin/clients')->assertSessionHasErrors(['standard_rate_card_id' => __('masterdata.clients.no_active_standard_card')]);
        $this->assertNull($client->fresh()->standard_rate_card_id);
    }

    public function test_due_dates_follow_payment_terms(): void
    {
        $issued = Carbon::parse('2026-09-07 10:00', 'Australia/Melbourne');

        $this->assertSame('2026-09-07', (new Client(['payment_terms' => 'prepaid']))->dueDateFor($issued)->toDateString());
        $this->assertSame('2026-09-30', (new Client(['payment_terms' => 'eom']))->dueDateFor($issued)->toDateString());
        $this->assertSame('2026-10-07', (new Client(['payment_terms' => 'net_30']))->dueDateFor($issued)->toDateString());
    }

    public function test_master_data_is_for_admin_customer_service_and_finance_only(): void
    {
        $this->actingAs($this->staff('customer_service'))->get('/admin/clients')->assertOk();
        $this->actingAs($this->staff('finance'))->get('/admin/carriers')->assertOk();
        $this->actingAs($this->staff('warehouse_operator'))->get('/admin/clients')->assertForbidden();
        $this->actingAs($this->staff('transport_operator'))->get('/admin/suppliers')->assertForbidden();
    }

    public function test_suppliers_and_carriers_are_created(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->post('/admin/suppliers', ['code' => 'PALLETS', 'name' => 'Pallet Supply', 'status' => 'active'])->assertRedirect('/admin/suppliers');
        $this->actingAs($admin)->post('/admin/carriers', ['code' => 'TRANSDIRECT', 'name' => 'Transdirect', 'status' => 'active'])->assertRedirect('/admin/carriers');

        $this->assertDatabaseHas('suppliers', ['code' => 'PALLETS']);
        $this->assertDatabaseHas('carriers', ['code' => 'TRANSDIRECT']);
        $this->actingAs($admin)->get('/admin/carriers')->assertOk()->assertSee('Transdirect');
    }
}
