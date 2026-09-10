<?php

namespace Tests\Feature\Orders;

use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Http\OrderValidation;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderApiTokenService;
use App\Modules\Warehouse\Services\AsnService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** 2026-09-10 audit, lane B package B2 (Orders majors): every finding here failed before the fix and is pinned by one assertion block. */
class OrderFormAuditFixesTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** Finding: a from_stock order of a client without ASN lines could never be confirmed and the page offered no way to link. */
    public function test_from_stock_draft_always_offers_the_asn_link_control_and_confirm_names_the_unlinked_lines(): void
    {
        $cs = $this->staff('customer_service');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];

        $this->actingAs($cs)->post('/orders', $this->payload($client, $job, ['lines' => [['description_cn' => '展示架', 'package_type' => 'carton', 'carton_qty' => 3], ['description_en' => 'Lamps', 'package_type' => 'carton', 'carton_qty' => 1]]]))->assertSessionHasNoErrors();
        $order = Order::query()->with('lines')->sole();

        // No ASN for this client yet: the control is still there (edit + add-line forms) with a Chinese hint, and the confirm button says why it would fail.
        $page = $this->actingAs($cs)->get(route('orders.show', $order))->assertOk();
        $page->assertSee('name="asn_line_id"', false)->assertSee(__('orders.drafts.no_asn_lines_yet'))->assertSee(__('orders.drafts.asn_filter_placeholder'))
            ->assertSee(__('orders.drafts.unlinked_warning', ['count' => 2]));
        $this->assertSame(3, substr_count($page->getContent(), '<select name="asn_line_id"'), 'two edit forms + the add-line form each carry the select');

        $response = $this->actingAs($cs)->post(route('orders.confirm', $order));
        $response->assertSessionHasErrors('order');
        $message = session('errors')->first('order');
        $this->assertStringContainsString('展示架', $message);
        $this->assertStringContainsString('Lamps', $message);
        $this->assertSame('received', $order->fresh()->operational_status);

        // Once the client has an ASN line the same control lists it (searchable by 唛头 / 品名) and the add-line form can link directly.
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$asnLine] = app(AsnService::class)->addLines($asn, [['consignment_mark' => 'MARK-A', 'description' => 'Display stands', 'expected_cartons' => 40]]);

        $this->actingAs($cs)->get(route('orders.show', $order))->assertOk()->assertSee('MARK-A')->assertDontSee(__('orders.drafts.no_asn_lines_yet'));
        $this->actingAs($cs)->post(route('orders.lines.store', $order), ['description_cn' => '说明书', 'package_type' => 'carton', 'carton_qty' => 1, 'asn_line_id' => $asnLine->id])->assertSessionHasNoErrors();
        $this->assertSame($asnLine->id, $order->lines()->where('description_cn', '说明书')->sole()->asn_line_id);
    }

    /** Finding: the add-line form stored every package type as carton (array union with the default on the left). */
    public function test_add_line_form_keeps_the_chosen_package_type(): void
    {
        $cs = $this->staff('customer_service');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $this->actingAs($cs)->post('/orders', $this->payload($client, $job))->assertSessionHasNoErrors();
        $order = Order::query()->sole();

        $this->actingAs($cs)->post(route('orders.lines.store', $order), ['description_cn' => '木托盘', 'package_type' => 'pallet', 'carton_qty' => 3])->assertSessionHasNoErrors();
        $this->assertSame('pallet', $order->lines()->where('description_cn', '木托盘')->sole()->package_type);

        $this->actingAs($cs)->post(route('orders.lines.store', $order), ['description_cn' => '默认纸箱', 'carton_qty' => 1])->assertSessionHasNoErrors();
        $this->assertSame('carton', $order->lines()->where('description_cn', '默认纸箱')->sole()->package_type);
    }

    /** Finding: a Job of another client aborted the manual form and the Excel import with a bare 422 page; now a field error + the form keeps its input. */
    public function test_job_of_another_client_is_a_field_error_on_the_order_form_and_the_import_form(): void
    {
        $cs = $this->staff('customer_service');
        $clientA = $this->client(['name' => 'Client A']);
        $clientB = $this->client(['name' => 'Client B']);
        $jobB = app(JobService::class)->create($clientB->id, 'loose')['job_id'];

        $this->actingAs($cs)->get(route('orders.create'))->assertOk()->assertSee('data-client-id="'.$clientB->id.'"', false);

        $this->actingAs($cs)->from(route('orders.create'))->post('/orders', $this->payload($clientA, $jobB, ['consignment_mark' => 'KEEP-ME']))
            ->assertRedirect(route('orders.create'))
            ->assertSessionHasErrors(['job_id' => __('orders.validation.job_client_mismatch')])
            ->assertSessionHasInput('consignment_mark', 'KEEP-ME');
        $this->assertSame(0, Order::query()->count());

        $import = $this->actingAs($cs)->get(route('orders.imports.create'))->assertOk();
        $import->assertSee('data-client-id="'.$clientB->id.'"', false)->assertSee('Client B'); // the job option shows its owner
        $this->actingAs($cs)->from(route('orders.imports.create'))->post(route('orders.imports.preview'), [
            'client_id' => $clientA->id, 'job_id' => $jobB, 'requested_date' => '2026-09-30', 'service_level' => 'express',
            'manifest' => UploadedFile::fake()->createWithContent('manifest.csv', "a,b\n1,2\n"),
        ])->assertRedirect(route('orders.imports.create'))->assertSessionHasErrors(['job_id' => __('orders.validation.job_client_mismatch')])->assertSessionHasInput('service_level', 'express');
        $this->assertDatabaseCount('order_imports', 0);
        $this->actingAs($cs)->get(route('orders.imports.create'))->assertOk()->assertSee('value="express" selected', false);
    }

    /** Finding: the hidden 提货 fieldset kept submitting its declared-package row, blocking (or silently polluting) a from_stock order. */
    public function test_declared_packages_are_ignored_unless_the_order_is_pure_transport(): void
    {
        $cs = $this->staff('customer_service');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];

        $this->actingAs($cs)->get(route('orders.create'))->assertOk()->assertSee('pickup.disabled = !pure', false);

        // weight only (blocking case) and qty + weight (silent-data case) — both are leftovers of a hidden fieldset.
        foreach ([[['package_type' => 'carton', 'weight_kg' => 30]], [['package_type' => 'carton', 'qty' => 2, 'weight_kg' => 30]]] as $leftover) {
            $this->actingAs($cs)->post('/orders', $this->payload($client, $job, ['declared_packages' => $leftover]))->assertSessionHasNoErrors();
        }
        $this->assertSame(2, Order::query()->count());
        $this->assertDatabaseCount('declared_packages', 0);
        $this->assertFalse(Order::query()->where('tailgate_required', true)->exists());
    }

    /** Finding: a goods row with only 箱数 typed vanished silently; it must now raise the description error (in Chinese, naming the row). */
    public function test_a_row_with_only_a_quantity_is_validated_instead_of_dropped(): void
    {
        $cs = $this->staff('customer_service');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];

        $this->actingAs($cs)->get(route('orders.create'))->assertOk()->assertDontSee('name="lines[0][carton_qty]" value="1"', false);

        $lines = [['description_cn' => '展示架', 'package_type' => 'carton', 'carton_qty' => 10], ['package_type' => 'carton', 'carton_qty' => 20]];
        $this->actingAs($cs)->post('/orders', $this->payload($client, $job, ['lines' => $lines]))->assertSessionHasErrors(['lines.1.description_cn']);
        $this->assertSame(0, Order::query()->count());
        $message = session('errors')->first('lines.1.description_cn');
        $this->assertStringContainsString('第 2 行', $message);
        $this->assertStringContainsString('品名', $message);
        $this->assertStringNotContainsString('field', $message);

        // A spare row that only carries the dropdown default is still pruned.
        $this->actingAs($cs)->post('/orders', $this->payload($client, $job, ['lines' => [$lines[0], ['package_type' => 'carton']]]))->assertSessionHasNoErrors();
        $this->assertSame(1, Order::query()->sole()->lines()->count());
    }

    /** Finding: rejected order forms printed English messages with raw array keys (lines.1.carton_qty). */
    public function test_order_form_validation_speaks_chinese_with_row_positions(): void
    {
        $cs = $this->staff('customer_service');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];

        $lines = [['description_cn' => '展示架', 'package_type' => 'carton', 'carton_qty' => 1], ['description_cn' => '纸箱样品', 'package_type' => 'carton']];
        $this->actingAs($cs)->post('/orders', $this->payload($client, $job, ['lines' => $lines]))->assertSessionHasErrors('lines.1.carton_qty');
        $messages = OrderValidation::messages();
        $this->assertSame(str_replace(':position', '2', $messages['lines.*.carton_qty.required']), session('errors')->first('lines.1.carton_qty'));

        $this->actingAs($cs)->post('/orders', $this->payload($client, $job, ['lines' => [['package_type' => 'carton']]]))->assertSessionHasErrors('lines');
        $this->assertSame($messages['lines.required_unless'], session('errors')->first('lines'));

        $pure = array_diff_key($this->payload($client, null, ['order_type' => 'pickup_deliver', 'declared_packages' => [['package_type' => 'carton', 'weight_kg' => 30]]]), ['lines' => true]);
        $this->actingAs($cs)->post('/orders', $pure)->assertSessionHasErrors(['declared_packages.0.qty', 'pickup_address_line']);
        $this->assertSame(str_replace(':position', '1', $messages['declared_packages.*.qty.required_with']), session('errors')->first('declared_packages.0.qty'));
        $this->assertSame($messages['pickup_address_line.required_if'], session('errors')->first('pickup_address_line'));

        $this->actingAs($cs)->post('/orders', $this->payload($client, $job, ['deliver_to_postcode' => '']))->assertSessionHasErrors('deliver_to_postcode');
        $this->assertStringContainsString(__('orders.fields.postcode'), session('errors')->first('deliver_to_postcode'));
        $this->assertStringNotContainsString('deliver to postcode', session('errors')->first('deliver_to_postcode'));
    }

    /** Finding: the documented API body has no package_type and the insert then died with a 500 (NOT NULL column). */
    public function test_api_order_without_package_type_defaults_to_carton_and_the_usage_example_shows_it(): void
    {
        $client = $this->client();
        $issued = app(OrderApiTokenService::class)->issue($client->id, 'client ERP', null);

        $body = ['order_type' => 'from_stock', 'external_ref' => 'PO-1001', 'deliver_to_name' => 'Test', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000', 'requested_date' => '2026-10-01', 'lines' => [['description_en' => 'Widget', 'carton_qty' => 10, 'asn_line_id' => null]]];
        $this->withToken($issued['plain'])->withHeader('Idempotency-Key', 'k-1')->postJson(route('orders.api.orders.store'), $body)->assertCreated();
        $this->assertSame('carton', Order::query()->sole()->lines()->sole()->package_type);

        $body['lines'][0]['package_type'] = null;
        $body['external_ref'] = 'PO-1002';
        $this->withToken($issued['plain'])->withHeader('Idempotency-Key', 'k-2')->postJson(route('orders.api.orders.store'), $body)->assertCreated();
        $this->assertSame(2, Order::query()->count());

        $this->actingAs($this->staff('admin'))->get(route('orders.api-tokens.index'))->assertOk()->assertSee('"package_type":"carton"', false);
    }

    /** @return array<string, mixed> */
    /** Finding (B5): the 确认订单 button rendered for every staff role while POST /confirm accepts only admin / customer_service / dispatcher. */
    public function test_confirm_button_is_offered_only_to_the_order_entry_roles(): void
    {
        $cs = $this->staff('customer_service');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $this->actingAs($cs)->post('/orders', $this->payload($client, $job))->assertSessionHasNoErrors();
        $order = Order::query()->sole();
        $confirmUrl = route('orders.confirm', $order);

        foreach (['finance', 'warehouse_supervisor', 'warehouse_operator', 'transport_operator'] as $role) {
            $this->actingAs($this->staff($role))->get(route('orders.show', $order))->assertOk()
                ->assertDontSee('action="'.$confirmUrl.'"', false)
                ->assertSee(__('orders.actions.confirm_needs_role'));
            $this->actingAs($this->staff($role))->post($confirmUrl)->assertForbidden();
        }
        foreach (['admin', 'customer_service', 'dispatcher'] as $role) {
            $this->actingAs($this->staff($role))->get(route('orders.show', $order))->assertOk()
                ->assertSee('action="'.$confirmUrl.'"', false)
                ->assertDontSee(__('orders.actions.confirm_needs_role'));
        }
    }

    /** Finding (B5): the API-token form came back empty after a rejected 名称, the PDF-draft form reset 服务等级 to 标准. */
    public function test_api_token_and_draft_forms_keep_their_input_after_a_validation_error(): void
    {
        $admin = $this->staff('admin');
        $client = $this->client(['name' => 'Token Client']);

        $tooLong = str_repeat('长', 101);
        $response = $this->actingAs($admin)->from(route('orders.api-tokens.index'))->post(route('orders.api-tokens.store'), ['client_id' => $client->id, 'name' => $tooLong]);
        $response->assertRedirect(route('orders.api-tokens.index'))->assertSessionHasErrors('name');
        $this->assertStringContainsString('令牌名称', session('errors')->first('name'));
        $this->assertStringNotContainsString('The name', session('errors')->first('name'));
        $page = $this->actingAs($admin)->get(route('orders.api-tokens.index'))->assertOk();
        $page->assertSee('<option value="'.$client->id.'" selected>', false)->assertSee('value="'.$tooLong.'"', false);

        $cs = $this->staff('customer_service');
        $this->actingAs($cs)->from(route('orders.drafts.create'))->post(route('orders.drafts.store'), [
            'client_id' => $client->id, 'service_level' => 'express', 'document' => UploadedFile::fake()->create('order.exe', 10),
        ])->assertRedirect(route('orders.drafts.create'))->assertSessionHasErrors('document');
        $this->actingAs($cs)->get(route('orders.drafts.create'))->assertOk()
            ->assertSee('<option value="express" selected>', false)
            ->assertSee('<option value="'.$client->id.'" selected>', false);
    }

    private function payload(Client $client, ?int $jobId, array $overrides = []): array
    {
        return array_replace([
            'client_id' => $client->id, 'job_id' => $jobId, 'order_type' => 'from_stock', 'external_ref' => 'REF-'.uniqid(),
            'deliver_to_name' => 'Receiver', 'deliver_to_phone' => '0400000000', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne',
            'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000', 'deliver_to_address_type' => 'business',
            'requested_date' => '2026-09-30', 'service_level' => 'standard',
            'lines' => [['description_cn' => '灯具', 'package_type' => 'carton', 'carton_qty' => 2, 'actual_weight_kg' => 10]],
        ], $overrides);
    }
}
