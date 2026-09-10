<?php

namespace Tests\Feature\MasterData;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** Audit 2026-09-10: global search offers a 客户 hit only to the roles that can open /admin/clients, and never the client's billing terms. */
class SearchGatingTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_client_hits_follow_the_master_data_roles(): void
    {
        $client = $this->client(['code' => 'EDWARD', 'name' => 'Edward Logistics', 'payment_terms' => 'eom', 'invoice_mode' => 'per_job']);

        foreach (['warehouse_operator', 'warehouse_supervisor', 'dispatcher', 'transport_operator'] as $role) {
            $this->actingAs($this->staff($role))->get('/admin/search?q=EDWARD')->assertOk()->assertDontSee('Edward Logistics')->assertDontSee(route('masterdata.clients.edit', $client));
        }
        foreach (['admin', 'customer_service', 'finance'] as $role) {
            $this->actingAs($this->staff($role))->get('/admin/search?q=EDWARD')->assertOk()->assertSee('Edward Logistics')->assertSee(route('masterdata.clients.edit', $client))
                ->assertDontSee('eom · per_job')->assertSee(__('masterdata.statuses.active'));
        }
    }
}
