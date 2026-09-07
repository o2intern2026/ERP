<?php

namespace Tests\Feature\MasterData;

use App\Modules\MasterData\Models\Client;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** A2: clients / suppliers / carriers master data with per-client billing settings. */
class ClientCrudTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function validClient(array $overrides = []): array
    {
        return $overrides + [
            'code' => 'EDWARD', 'name' => 'Edward Logistics', 'abn' => '12 345 678 901', 'leg_type' => 'both', 'status' => 'active',
            'payment_terms' => 'net_30', 'invoice_mode' => 'per_job', 'default_markup_percent' => '20', 'dispatch_cutoff_time' => '14:00',
            'state' => 'VIC', 'postcode' => '3175',
        ];
    }

    public function test_admin_creates_and_edits_a_client(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->post('/admin/clients', $this->validClient())->assertRedirect('/admin/clients');
        $client = Client::query()->where('code', 'EDWARD')->firstOrFail();
        $this->assertSame('net_30', $client->payment_terms);
        $this->assertSame('14:00:00', $client->dispatch_cutoff_time);

        $this->actingAs($admin)->get('/admin/clients')->assertOk()->assertSee('Edward Logistics')->assertSee(__('masterdata.invoice_modes.per_job'));

        $this->actingAs($admin)->put("/admin/clients/{$client->id}", $this->validClient(['invoice_mode' => 'monthly', 'payment_terms' => 'eom']))->assertRedirect('/admin/clients');
        $this->assertSame(['monthly', 'eom'], [$client->fresh()->invoice_mode, $client->fresh()->payment_terms]);
    }

    public function test_payment_terms_must_be_prepaid_eom_or_net_n(): void
    {
        $admin = $this->staff();

        foreach (['net30', 'cod', 'net_'] as $bad) {
            $this->actingAs($admin)->post('/admin/clients', $this->validClient(['payment_terms' => $bad, 'code' => 'X'.$bad]))->assertSessionHasErrors('payment_terms');
        }
        foreach (['prepaid', 'eom', 'net_7', 'net_14', 'net_30'] as $good) {
            $this->actingAs($admin)->post('/admin/clients', $this->validClient(['payment_terms' => $good, 'code' => 'OK'.strtoupper(str_replace('_', '', $good))]))->assertSessionHasNoErrors();
        }
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
