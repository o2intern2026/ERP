<?php

namespace Tests\Feature\MasterData;

use App\Modules\MasterData\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** CHANGE_REQUESTS #145 自动导入: admin sets a client's import defaults (group_by, address type, auto-confirm, inbox, notify email) on the client form. */
class ClientImportDefaultsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** @return array<string, mixed> */
    private function form(Client $client, array $overrides = []): array
    {
        return $overrides + [
            'code' => $client->code, 'name' => $client->name, 'leg_type' => 'both', 'status' => 'active',
            'payment_terms' => 'eom', 'invoice_mode' => 'per_job', 'default_markup_percent' => '0',
        ];
    }

    public function test_admin_sets_and_clears_the_import_defaults_on_the_client_form(): void
    {
        $admin = $this->staff('admin');
        $client = $this->client(['code' => 'DDD']);
        $this->assertSame(Client::IMPORT_DEFAULTS, $client->importDefaults(), 'nothing stored → today\'s rules, nothing automated');

        $page = $this->actingAs($admin)->get(route('masterdata.clients.edit', $client))->assertOk()
            ->assertSee(__('masterdata.clients.import_section'))->assertSee('name="import_defaults[group_by]"', false)->assertSee('name="import_defaults[inbox_enabled]"', false)
            ->assertSee(route('orders.api.imports.store'))->assertSee('imports/inbox/DDD');
        $this->assertDoesNotMatchRegularExpression('/masterdata\.(clients|fields)\.|orders\.imports\./', $page->getContent());

        $this->actingAs($admin)->put(route('masterdata.clients.update', $client), $this->form($client, [
            'import_defaults' => ['group_by' => 'recipient', 'address_type_default' => 'residential', 'auto_confirm' => '1', 'inbox_enabled' => '1', 'notify_email' => ' lists@example.test '],
        ]))->assertSessionHasNoErrors()->assertRedirect(route('masterdata.index'));
        $this->assertSame(['group_by' => 'recipient', 'address_type_default' => 'residential', 'auto_confirm' => true, 'inbox_enabled' => true, 'notify_email' => 'lists@example.test'], $client->fresh()->importDefaults());

        // Unticked checkboxes are absent from the post → false; a bad email is refused and nothing changes.
        $this->actingAs($admin)->put(route('masterdata.clients.update', $client), $this->form($client, ['import_defaults' => ['group_by' => 'mark', 'address_type_default' => 'auto', 'notify_email' => 'not-an-email']]))
            ->assertSessionHasErrors(['import_defaults.notify_email']);
        $this->assertTrue($client->fresh()->importDefaults()['inbox_enabled']);
        $this->actingAs($admin)->put(route('masterdata.clients.update', $client), $this->form($client, ['import_defaults' => ['group_by' => 'mark', 'address_type_default' => 'auto', 'notify_email' => '']]))->assertSessionHasNoErrors();
        $this->assertSame(Client::IMPORT_DEFAULTS, $client->fresh()->importDefaults());
        // A caller that never posts the fieldset leaves the stored defaults untouched.
        $client->update(['import_defaults' => ['inbox_enabled' => true]]);
        $this->actingAs($admin)->put(route('masterdata.clients.update', $client), $this->form($client))->assertSessionHasNoErrors();
        $this->assertTrue($client->fresh()->importDefaults()['inbox_enabled']);
    }
}
