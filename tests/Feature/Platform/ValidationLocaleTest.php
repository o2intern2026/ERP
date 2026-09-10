<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-10 (A2-zh-validation): every validation failure must render in Chinese with the on-screen
 * field name — never Laravel's English fallback with the raw request key (lang/zh/validation.php).
 */
class ValidationLocaleTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_zh_validation_file_covers_every_framework_rule(): void
    {
        $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $zh = require lang_path('zh/validation.php');

        $missing = [];
        foreach ($en as $rule => $message) {
            if (in_array($rule, ['custom', 'attributes'], true)) {
                continue;
            }
            foreach (is_array($message) ? array_keys($message) : [null] as $variant) {
                $present = $variant === null ? array_key_exists($rule, $zh) : isset($zh[$rule][$variant]);
                if (! $present) {
                    $missing[] = $variant === null ? $rule : "$rule.$variant";
                }
            }
        }

        $this->assertSame([], $missing, 'lang/zh/validation.php is missing framework rule keys — they would render in English');
    }

    public function test_masterdata_client_form_errors_are_chinese_with_the_on_screen_label(): void
    {
        $admin = $this->staff();

        $response = $this->actingAs($admin)->from('/admin/clients/create')->post('/admin/clients', [
            'code' => 'ACME LOGISTICS', 'name' => 'Acme', 'leg_type' => 'both', 'status' => 'active',
            'payment_terms' => 'eom', 'invoice_mode' => 'per_job', 'default_markup_percent' => '1000',
        ]);

        $response->assertRedirect('/admin/clients/create')
            ->assertSessionHasErrors(['code' => '编码 只能包含字母、数字、短横线和下划线。'])
            ->assertSessionHasErrors(['default_markup_percent' => '默认加成 % 不能大于 999.99。']);

        $page = $this->actingAs($admin)->get('/admin/clients/create')->assertOk();
        $page->assertSee('编码 只能包含字母、数字、短横线和下划线。')
            ->assertDontSee('The code field')
            ->assertDontSee('default markup percent')
            ->assertDontSee('field is required')
            ->assertDontSee('must not be greater than');
    }

    public function test_platform_required_if_names_both_fields_in_chinese(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->from('/admin/users/create')->post('/admin/users', [
            'name' => 'Client Person', 'email' => 'client.person@example.com', 'password' => 'secret-123', 'role' => 'client',
        ])->assertRedirect('/admin/users/create')
            ->assertSessionHasErrors(['client_id' => '当 角色 为 client 时,客户 必填。']);

        $this->actingAs($admin)->get('/admin/users/create')->assertOk()
            ->assertSee('客户 必填')
            ->assertDontSee('client id')
            ->assertDontSee('is required when');
    }

    public function test_warehouse_scan_forms_use_chinese_field_names_including_wildcard_rows(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $warehouse = $this->warehouse();
        $rcv = $this->location($warehouse, 'receiving');

        $this->actingAs($supervisor)->from('/warehouse/config/locations')->post('/warehouse/config/locations', [
            'warehouse_id' => $warehouse->id, 'zone' => 'A 1', 'aisle' => '01', 'bin' => '01', 'type' => 'bulk',
        ])->assertRedirect('/warehouse/config/locations')
            ->assertSessionHasErrors(['zone' => '区 只能包含字母和数字。']);

        $this->actingAs($supervisor)->get('/warehouse/config/locations')->assertOk()
            ->assertSee('区 只能包含字母和数字。')
            ->assertDontSee('The zone field');

        $this->actingAs($supervisor)->post(route('warehouse.receiving.unplanned.store'), [
            'client_id' => $this->client()->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel', 'receiving_location_id' => $rcv->id,
            'rows' => [['consignment_mark' => 'ONLY-MARK', 'description' => '', 'received_cartons' => 4, 'damaged_cartons' => 0, 'unit_type' => 'carton', 'unit_count' => 1]],
        ])->assertSessionHasErrors(['rows.0.description' => '品名 必填。']);
    }
}
