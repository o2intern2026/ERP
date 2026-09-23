<?php

namespace Tests\Feature\Portal;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\Services\SpreadsheetManifestParser;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #143 拼箱清单格式直接导入: the client's English consolidation list uploads AS IS. The synthetic fixture
 * `tests/Fixtures/imports/consolidation_dsf.xlsx` copies the real layout with fictional data — row 1 a Chinese group header, row 2 the
 * 27 headers (`Sender's Name … ChannelWaybillNumber, <unnamed tracking column>, Country, State/Province, City, Suburb, Street Name,
 * Unit/Street Number, Recipient, Recipient's Email, Recipient's Phone Number, Postal Code, Detailed Address, Battery Type, Battery
 * Packaging, Commodity, TTL VALUE(AUD), 商品数量, 每箱产品总价 (AUD), Length(cm), Width(cm), Height(cm), Weight(kg), Cube(m3)`), rows 3–8
 * six cartons for five recipients: two rows for recipient A (12 kg and 30 kg, waybills CW1001-1 / CW1001-2, state "Queensland"),
 * B ("Vic", phone 61…, an English commodity), C, D ("New South Wales", "+61 …" phone) and E; the "Suburb" column holds the street line
 * while "City" holds the suburb; phones without the leading 0; one row = one carton (no 箱数 column).
 */
class PortalConsolidationImportTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const FIXTURE = 'tests/Fixtures/imports/consolidation_dsf.xlsx';

    private function upload(): UploadedFile
    {
        return new UploadedFile(base_path(self::FIXTURE), 'consolidation.xlsx', null, null, true);
    }

    public function test_the_consolidation_list_is_read_as_is_one_carton_per_row_with_city_as_the_suburb(): void
    {
        $parsed = app(SpreadsheetManifestParser::class)->parse(base_path(self::FIXTURE));

        $this->assertSame([], $parsed['errors']);
        $this->assertSame([3, 4, 5, 6, 7, 8], array_column($parsed['rows'], 'row'), 'the header is row 2 (the Chinese group header above it is skipped), data rows keep Excel row numbers');
        $this->assertSame(['CW1001-1', 'CW1001-2', 'CW1002', 'CW1003-1', 'CW1004', 'CW1005-1'], array_column($parsed['rows'], 'consignment_mark'), 'ChannelWaybillNumber is the mark');
        $this->assertSame([1, 1, 1, 1, 1, 1], array_column($parsed['rows'], 'carton_qty'), 'no 箱数 column → one carton per row');
        $this->assertSame([0, 'carton_qty'], [$parsed['warnings'][0]['row'], $parsed['warnings'][0]['column']]);
        $this->assertSame(__('orders.imports.warnings.one_carton_per_row'), $parsed['warnings'][0]['message']);
        $this->assertStringContainsString('一行 = 一箱', $parsed['warnings'][0]['message']);

        $a = $parsed['rows'][0];
        $this->assertSame('Brisbane City', $a['deliver_to_suburb'], 'the leftmost of two columns mapping to the same field wins: City, not the misused Suburb column');
        $this->assertSame('1 Sample St, Brisbane City', $a['deliver_to_address'], 'Detailed Address is the address');
        $this->assertSame(['Test Recipient A', '0412000001', '4000', 'QLD'], [$a['deliver_to_name'], $a['deliver_to_phone'], $a['deliver_to_postcode'], $a['deliver_to_state']], 'Recipient / Recipient\'s Phone Number (leading 0 restored) / Postal Code / "Queensland"');
        $this->assertSame(['测试商品A', null], [$a['description_cn'], $a['description_en']], 'a Chinese Commodity lands in 中文品名');
        $this->assertSame([10, 500, 5000], [$a['unit_qty'], $a['unit_price_cents'], $a['total_price_cents']], '商品数量; TTL VALUE(AUD) is the unit price because 5 × 10 = 每箱产品总价 50');
        $this->assertSame([400, 300, 200, 12.0, 0.024], [$a['length_mm'], $a['width_mm'], $a['height_mm'], $a['actual_weight_kg'], $a['cbm']], 'cm → mm, Weight(kg), Cube(m3)');
        $this->assertSame([null, null, false], [$a['deliver_to_address_type'], $a['storage_tier'], $a['storage_tier_declared']], 'no 地址类型 / 存储等级 column → the caller\'s defaults');
        $this->assertArrayNotHasKey('description_auto', $a);
        $this->assertArrayNotHasKey('value_aud', $a);

        $b = $parsed['rows'][2];
        $this->assertSame(['VIC', '0412000002', null, 'Sample goods C'], [$b['deliver_to_state'], $b['deliver_to_phone'], $b['description_cn'], $b['description_en']], '"Vic", a 61… phone, an English commodity goes to 英文品名');
        $this->assertSame(['NSW', '0412000004'], [$parsed['rows'][4]['deliver_to_state'], $parsed['rows'][4]['deliver_to_phone']], '"New South Wales" and "+61 412 000 004"');
        $this->assertSame(30.0, $parsed['rows'][1]['actual_weight_kg']);
        $this->assertStringContainsString("第 3 行「Recipient's Phone Number」补回了前导 0（412000001 → 0412000001）", implode(' ', array_column($parsed['warnings'], 'message')));
        // Unknown columns (Sender's *, Country, Battery *, Recipient's Email, Street Name, Unit/Street Number, the unnamed tracking column) are ignored, not errors.
        $this->assertSame('Australia', $a['raw_json']['Country']);
        $this->assertSame('DSFAU0000000001', $a['raw_json']['E'], 'the unnamed column is kept in the raw row under its letter');
    }

    public function test_grouping_by_recipient_makes_one_order_per_consignee_with_the_waybill_in_each_line_and_a_residential_default_reaches_the_tailgate(): void
    {
        Storage::fake('local');
        $client = $this->client(['name' => 'Consolidator']);
        $user = $this->clientUser($client);

        // The upload form offers both options with today's rules preselected and the consolidation hint; no raw lang key.
        $form = $this->actingAs($user)->get(route('portal.asns.imports.create'))->assertOk()
            ->assertSee('name="group_by"', false)->assertSee('value="recipient"', false)->assertSee('name="address_type_default"', false)->assertSee('value="residential"', false)
            ->assertSee('ChannelWaybillNumber')->assertSee(__('portal.inbound.options.group_by_options.mark'));
        $this->assertDoesNotMatchRegularExpression('/portal\.inbound\.|orders\.imports\./', $form->getContent());

        $this->actingAs($user)->post(route('portal.asns.imports.store'), [
            'manifest' => $this->upload(), 'group_by' => 'recipient', 'address_type_default' => 'residential', 'container_no' => 'cosu0000001', 'container_size' => '40',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $import = OrderImport::query()->sole();
        $this->assertSame(['recipient', 'residential'], [$import->errors['context']['group_by'], $import->errors['context']['address_type_default']], 'the options are recorded with the import');
        $this->assertSame([6, 0], [$import->row_count, $import->error_count]);
        $groups = collect($import->errors['groups']);
        $this->assertCount(5, $groups, '6 rows → 5 recipients');
        $this->assertSame(['ready', 'ready', 'ready', 'ready', 'ready'], $groups->pluck('status')->all());
        $this->assertSame(['CW1001', 'CW1002', 'CW1003', 'CW1004', 'CW1005'], $groups->pluck('consignment_mark')->all(), 'the order mark is the first row\'s waybill root ("-<n>" stripped)');
        $a = $groups->firstWhere('consignment_mark', 'CW1001');
        $this->assertSame([3, 4], $a['row_numbers']);
        $this->assertSame(['CW1001-1 · 测试商品A', 'CW1001-2 · 测试商品B'], array_column($a['rows'], 'description_cn'), 'every row keeps its own waybill in the line description');
        $this->assertSame('CW1002 · Sample goods C', $groups->firstWhere('consignment_mark', 'CW1002')['rows'][0]['description_en'], 'an English-only description carries it too');
        $this->assertSame(['residential', 'residential', 'residential', 'residential', 'residential'], $groups->pluck('deliver_to_address_type')->all(), 'the default applies to every row without an explicit 地址类型');

        // The preview names the rule and the resulting order count, and the line descriptions show the waybills.
        $page = $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()
            ->assertSee(__('portal.inbound.options.group_by_options.recipient'))->assertSee(__('portal.inbound.options.preview_orders', ['count' => 5]))
            ->assertSee(__('portal.inbound.options.address_type_defaults.residential'))->assertSee('CW1001-2 · 测试商品B')->assertSee(__('portal.inbound.ready_count', ['ready' => 5, 'blocked' => 0, 'errors' => 0]))
            ->assertSee(__('portal.inbound.actions.confirm'))->assertSee('一行 = 一箱');
        $this->assertSame(5, substr_count($page->getContent(), __('portal.inbound.residential_tailgate')));
        $this->assertDoesNotMatchRegularExpression('/portal\.inbound\.|orders\.imports\./', $page->getContent());

        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import))->assertSessionHasNoErrors()->assertRedirect();
        $orders = Order::query()->withoutGlobalScopes()->with('lines')->get()->keyBy('consignment_mark');
        $this->assertCount(5, $orders);
        $this->assertSame(1, $orders->pluck('job_id')->unique()->count(), 'one Job per submission');
        $heavy = $orders['CW1001'];
        $this->assertSame(['Test Recipient A', '0412000001', '1 Sample St, Brisbane City', 'Brisbane City', 'QLD', '4000', 'residential'], [
            $heavy->deliver_to_name, $heavy->deliver_to_phone, $heavy->deliver_to_address, $heavy->deliver_to_suburb, $heavy->deliver_to_state, $heavy->deliver_to_postcode, $heavy->deliver_to_address_type,
        ]);
        $this->assertSame(['CW1001-1 · 测试商品A', 'CW1001-2 · 测试商品B'], $heavy->lines->sortBy('id')->pluck('description_cn')->values()->all());
        $this->assertSame([[1, 12.0, 500, 5000, 10], [1, 30.0, 250, 1000, 4]], $heavy->lines->sortBy('id')->map(fn ($l) => [(int) $l->carton_qty, (float) $l->actual_weight_kg, (int) $l->unit_price_cents, (int) $l->total_price_cents, (int) $l->unit_qty])->values()->all());
        // OMS-13 TailgateRule (unchanged): the 30 kg carton is a heavy piece; the 3 kg parcel needs it only because of the residential default.
        $this->assertSame([true, 'heavy_item'], [(bool) $heavy->tailgate_required, $heavy->tailgate_reason]);
        $this->assertSame([true, 'residential_address', 'residential'], [(bool) $orders['CW1004']->tailgate_required, $orders['CW1004']->tailgate_reason, $orders['CW1004']->deliver_to_address_type]);
        $this->assertSame('CW1002 · Sample goods C', $orders['CW1002']->lines->first()->description_en);
        $this->assertSame(['VIC', '0412000002'], [$orders['CW1002']->deliver_to_state, $orders['CW1002']->deliver_to_phone]);
        $this->actingAs($user)->get(route('portal.orders.show', $heavy))->assertOk()->assertSee('CW1001-2 · 测试商品B');

        // Staff read the same list with its options (read-only for a portal submission) and the orders it produced.
        $this->actingAs($this->staff('customer_service'))->get(route('orders.imports.show', $import))->assertOk()
            ->assertSee(__('orders.imports.options.group_by_options.recipient'))->assertSee(__('orders.imports.options.address_type_defaults.residential'))
            ->assertSee(__('orders.imports.options.preview_orders', ['count' => 5]))->assertSee($heavy->order_no);
    }

    public function test_grouping_by_mark_keeps_one_order_per_waybill_with_the_automatic_address_type(): void
    {
        Storage::fake('local');
        $user = $this->clientUser($this->client(['name' => 'Consolidator']));

        // No options posted → today's rules (by mark, auto address type).
        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->upload()])->assertSessionHasNoErrors()->assertRedirect();
        $import = OrderImport::query()->sole();
        $this->assertSame(['mark', 'auto'], [$import->errors['context']['group_by'], $import->errors['context']['address_type_default']]);
        $groups = collect($import->errors['groups']);
        $this->assertCount(6, $groups, 'every waybill is its own order');
        $this->assertSame(['CW1001-1', 'CW1001-2', 'CW1002', 'CW1003-1', 'CW1004', 'CW1005-1'], $groups->pluck('consignment_mark')->all());
        $this->assertSame('测试商品B', $groups->firstWhere('consignment_mark', 'CW1001-2')['rows'][0]['description_cn'], 'no waybill prefix under mark grouping');
        $this->assertSame(['business'], $groups->pluck('deliver_to_address_type')->unique()->values()->all(), 'auto: no address book, no FBA reference → business');
        $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()
            ->assertSee(__('portal.inbound.options.group_by_options.mark'))->assertSee(__('portal.inbound.options.preview_orders', ['count' => 6]))->assertSee(__('portal.inbound.options.address_type_defaults.auto'));

        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import))->assertSessionHasNoErrors();
        $orders = Order::query()->withoutGlobalScopes()->with('lines')->get()->keyBy('consignment_mark');
        $this->assertCount(6, $orders);
        $this->assertSame([true, 'heavy_item', 'business'], [(bool) $orders['CW1001-2']->tailgate_required, $orders['CW1001-2']->tailgate_reason, $orders['CW1001-2']->deliver_to_address_type], 'the 30 kg carton still needs the tailgate');
        $this->assertSame([false, null], [(bool) $orders['CW1004']->tailgate_required, $orders['CW1004']->tailgate_reason], 'a 3 kg business parcel does not');
        $this->assertSame([1, 1], [(int) $orders['CW1001-1']->lines->first()->carton_qty, $orders['CW1001-1']->lines->count()]);
    }

    public function test_the_staff_import_offers_the_same_options_and_groups_by_recipient_too(): void
    {
        $cs = $this->staff('customer_service');
        $client = $this->client(['name' => 'Consolidator']);
        $job = app(JobService::class)->create($client->id, 'loose');

        $form = $this->actingAs($cs)->get(route('orders.imports.create'))->assertOk()
            ->assertSee('name="group_by"', false)->assertSee('value="recipient"', false)->assertSee('name="address_type_default"', false)->assertSee(__('orders.imports.options.group_by_options.recipient'));
        $this->assertDoesNotMatchRegularExpression('/orders\.imports\./', $form->getContent());

        $this->actingAs($cs)->post(route('orders.imports.preview'), [
            'client_id' => $client->id, 'job_id' => $job['job_id'], 'requested_date' => '2026-10-15', 'service_level' => 'standard',
            'manifest' => $this->upload(), 'group_by' => 'recipient', 'address_type_default' => 'business',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $import = OrderImport::query()->sole();
        $this->assertSame(['excel', 'recipient', 'business'], [$import->source, $import->errors['context']['group_by'], $import->errors['context']['address_type_default']]);
        $ready = collect($import->errors['groups'])->where('status', 'ready');
        $this->assertCount(5, $ready);
        $this->actingAs($cs)->get(route('orders.imports.show', $import))->assertOk()
            ->assertSee(__('orders.imports.options.group_by_options.recipient'))->assertSee(__('orders.imports.options.preview_orders', ['count' => 5]))->assertSee('CW1001')->assertSee('一行 = 一箱');

        $this->actingAs($cs)->post(route('orders.imports.confirm', $import), ['groups' => $ready->pluck('key')->all()])->assertSessionHasNoErrors()->assertRedirect();
        $orders = Order::query()->with('lines')->get()->keyBy('consignment_mark');
        $this->assertCount(5, $orders);
        $this->assertSame([$job['job_id'], 'business', 2, 'CW1001-2 · 测试商品B'], [$orders['CW1001']->job_id, $orders['CW1001']->deliver_to_address_type, $orders['CW1001']->lines->count(), $orders['CW1001']->lines->sortBy('id')->last()->description_cn]);
        $this->assertSame([true, 'heavy_item'], [(bool) $orders['CW1001']->tailgate_required, $orders['CW1001']->tailgate_reason]);
        $this->assertFalse((bool) $orders['CW1004']->tailgate_required, 'business default: the 3 kg parcel needs no tailgate');

        // An unknown option value is refused by validation, nothing stored.
        $this->actingAs($cs)->post(route('orders.imports.preview'), [
            'client_id' => $client->id, 'job_id' => $job['job_id'], 'requested_date' => '2026-10-15', 'service_level' => 'standard', 'manifest' => $this->upload(), 'group_by' => 'colour',
        ])->assertSessionHasErrors('group_by');
        $this->assertSame(1, OrderImport::query()->count());
    }

    public function test_the_manual_list_carries_the_options_through_a_draft_and_groups_typed_rows_by_recipient(): void
    {
        Storage::fake('local');
        $user = $this->clientUser($this->client(['name' => 'Manual Co']));
        $row = fn (array $overrides = []): array => $overrides + [
            'consignment_mark' => 'CW2001-1', 'description_cn' => '台灯', 'description_en' => '', 'package_type' => 'carton', 'carton_qty' => '1',
            'actual_weight_kg' => '6', 'length_mm' => '400', 'width_mm' => '300', 'height_mm' => '300',
            'deliver_to_name' => 'Ms Li', 'deliver_to_phone' => '0412 345 678', 'deliver_to_address' => '8 Rose St', 'deliver_to_suburb' => 'Box Hill',
            'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3128', 'deliver_to_address_type' => '', 'fba_reference' => '', 'storage_tier' => '', 'requested_date' => '',
        ];

        $this->actingAs($user)->get(route('portal.asns.imports.manual.create'))->assertOk()->assertSee('name="group_by"', false)->assertSee('name="address_type_default"', false);

        // A draft keeps the chosen options and reopens with them selected.
        $this->actingAs($user)->post(route('portal.asns.imports.manual.store'), ['action' => 'draft', 'group_by' => 'recipient', 'address_type_default' => 'residential', 'rows' => [$row()]])->assertSessionHasNoErrors()->assertRedirect();
        $draft = OrderImport::query()->sole();
        $this->assertSame(['draft', 'recipient', 'residential'], [$draft->status, $draft->errors['context']['group_by'], $draft->errors['context']['address_type_default']]);
        $this->actingAs($user)->get(route('portal.asns.imports.manual.edit', $draft))->assertOk()
            ->assertSee('<option value="recipient" selected', false)->assertSee('<option value="residential" selected', false);

        // Submitted: two waybills of the same recipient become ONE order with the root mark, each line carrying its waybill; residential by default.
        $this->actingAs($user)->post(route('portal.asns.imports.manual.store'), [
            'action' => 'preview', 'draft_id' => $draft->id, 'group_by' => 'recipient', 'address_type_default' => 'residential',
            'rows' => [$row(), $row(['consignment_mark' => 'CW2001-2', 'description_cn' => '床头柜', 'actual_weight_kg' => '7'])],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $import = $draft->fresh();
        $this->assertSame('pending', $import->status);
        $groups = collect($import->errors['groups']);
        $this->assertCount(1, $groups);
        $this->assertSame(['CW2001', 'residential', ['CW2001-1 · 台灯', 'CW2001-2 · 床头柜']], [$groups[0]['consignment_mark'], $groups[0]['deliver_to_address_type'], array_column($groups[0]['rows'], 'description_cn')]);
        $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()->assertSee(__('portal.inbound.options.preview_orders', ['count' => 1]))->assertSee(__('portal.inbound.residential_tailgate'));

        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import))->assertSessionHasNoErrors();
        $order = Order::query()->withoutGlobalScopes()->with('lines')->sole();
        $this->assertSame(['CW2001', 'residential', true, 'residential_address', 2], [$order->consignment_mark, $order->deliver_to_address_type, (bool) $order->tailgate_required, $order->tailgate_reason, $order->lines->count()]);
    }
}
