<?php

namespace Tests\Feature\Portal;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\Services\OrderInboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #144 批量导入 from 新建订单 for both order types. The upload page carries `order_type`; a 现场提货直送 list takes the
 * pickup party + 要求送达日 instead of the inbound context and becomes pickup_deliver orders sharing that pickup address — every row a
 * declared package with its own weight (required, as on the order form), one transport_only Job, no ASN. The synthetic consolidation
 * fixture of CR #143 (6 cartons, 5 recipients, fictional data) is the list.
 */
class PortalOrderTypeImportTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const FIXTURE = 'tests/Fixtures/imports/consolidation_dsf.xlsx';

    private function upload(): UploadedFile
    {
        return new UploadedFile(base_path(self::FIXTURE), 'consolidation.xlsx', null, null, true);
    }

    /** @return array<string, mixed> */
    private function pickupParty(): array
    {
        return ['name' => 'Test Shipper', 'phone' => '02 9000 0000', 'address' => '5 Depot Rd', 'suburb' => 'Alexandria', 'state' => 'NSW', 'postcode' => '2015'];
    }

    public function test_the_new_order_page_links_to_the_upload_for_both_order_types_and_the_link_preselects_the_type(): void
    {
        $user = $this->clientUser($this->client(['name' => 'Bulk Client']));

        $this->actingAs($user)->get(route('portal.orders.create'))->assertOk()
            ->assertSee(__('portal.create.import_title'))
            ->assertSee(route('portal.asns.imports.create', ['order_type' => 'from_stock']), false)
            ->assertSee(route('portal.asns.imports.create', ['order_type' => 'pickup_deliver']), false);

        $pickupForm = $this->actingAs($user)->get(route('portal.asns.imports.create', ['order_type' => 'pickup_deliver']))->assertOk()
            ->assertSee('name="order_type" value="pickup_deliver" checked', false)
            ->assertSee('name="pickup[name]"', false)->assertSee('name="pickup[postcode]"', false)->assertSee('name="requested_date"', false)
            ->assertSee(__('orders.types.pickup_deliver'))->assertSee(__('portal.inbound.pickup.weight_hint'))->assertSee('ChannelWaybillNumber');
        $this->assertDoesNotMatchRegularExpression('/portal\.(inbound|create)\.|orders\.(imports|types)\./', $pickupForm->getContent(), 'no raw lang key on the page');

        // Without a type (预报入库's button) the page is today's inbound list: 库存出库配送 preselected, inbound context on the page.
        $this->actingAs($user)->get(route('portal.asns.imports.create'))->assertOk()
            ->assertSee('name="order_type" value="from_stock" checked', false)->assertSee('name="container_size"', false)->assertSee('name="inbound_transport"', false); // CR #158: 柜号 is generated, 柜型 still typed
    }

    public function test_a_pickup_deliver_list_needs_the_pickup_party_and_a_delivery_date(): void
    {
        Storage::fake('local');
        $user = $this->clientUser($this->client(['name' => 'Bulk Client']));

        $this->actingAs($user)->from(route('portal.asns.imports.create', ['order_type' => 'pickup_deliver']))
            ->post(route('portal.asns.imports.store'), ['order_type' => 'pickup_deliver', 'manifest' => $this->upload()])
            ->assertRedirect(route('portal.asns.imports.create', ['order_type' => 'pickup_deliver']))
            ->assertSessionHasErrors(['pickup', 'requested_date']);
        $this->assertSame(0, OrderImport::query()->count(), 'nothing is stored before the pickup party is complete');

        $this->actingAs($user)->post(route('portal.asns.imports.store'), [
            'order_type' => 'pickup_deliver', 'manifest' => $this->upload(), 'requested_date' => today()->addDays(3)->toDateString(),
            'pickup' => ['name' => 'Test Shipper', 'address' => '5 Depot Rd', 'suburb' => 'Alexandria', 'state' => 'XX', 'postcode' => '20150'],
        ])->assertSessionHasErrors(['pickup.state', 'pickup.postcode']);
        $this->assertSame(0, OrderImport::query()->count());
    }

    public function test_a_pickup_deliver_list_becomes_pickup_deliver_orders_sharing_the_pickup_address_with_a_declared_package_per_carton(): void
    {
        Storage::fake('local');
        $client = $this->client(['name' => 'Bulk Client']);
        $user = $this->clientUser($client);
        $requested = today()->addDays(5)->toDateString();

        $this->actingAs($user)->post(route('portal.asns.imports.store'), [
            'order_type' => 'pickup_deliver', 'manifest' => $this->upload(), 'group_by' => 'recipient', 'address_type_default' => 'residential',
            'requested_date' => $requested, 'pickup' => $this->pickupParty(),
            // Inbound fields posted by mistake are ignored for this type: nothing of the ASN world reaches a 提货直送 list.
            'container_no' => 'cosu0000001', 'inbound_transport' => 'we_collect',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $import = OrderImport::query()->sole();
        $context = $import->errors['context'];
        $this->assertSame('pickup_deliver', $context['order_type']);
        $this->assertSame('pickup_deliver', $import->orderType());
        $this->assertNull($context['inbound'], 'no inbound context, no collection request');
        // MySQL's JSON type stores object keys in its own order, so the comparison is order-insensitive.
        $this->assertEquals(['name' => 'Test Shipper', 'phone' => '02 9000 0000', 'address' => '5 Depot Rd', 'suburb' => 'Alexandria', 'state' => 'NSW', 'postcode' => '2015'], $context['pickup'], 'the pickup party as typed');
        $this->assertSame($requested, $context['requested_date']);
        $this->assertSame(['recipient', 'residential'], [$context['group_by'], $context['address_type_default']]);
        $groups = collect($import->errors['groups']);
        $this->assertCount(5, $groups, '6 cartons → 5 recipients');
        $this->assertSame(['ready', 'ready', 'ready', 'ready', 'ready'], $groups->pluck('status')->all(), 'every row of the fixture carries a weight');
        $this->assertSame([null], $groups->flatMap(fn ($group) => collect($group['rows'])->pluck('storage_tier'))->unique()->values()->all(), 'nothing is stored for a 提货直送 list: no tier');
        $this->assertStringNotContainsString('存储等级', implode(' ', array_column($import->errors['warnings'], 'message')), 'no value-rule tier pre-fill either');

        $preview = $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()
            ->assertSee(__('portal.inbound.show_title_pickup', ['id' => $import->id]))
            ->assertSee(__('portal.inbound.sections.pickup'))->assertSee('Test Shipper')->assertSee('5 Depot Rd, Alexandria NSW 2015')
            ->assertDontSee(__('portal.inbound.fields.container_no'))->assertDontSee(__('portal.inbound.fields.storage_tier'))
            ->assertSee(__('portal.inbound.options.preview_orders', ['count' => 5]));
        $this->assertDoesNotMatchRegularExpression('/portal\.inbound\.|orders\.(imports|types)\./', $preview->getContent());

        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import))->assertRedirect(route('portal.asns.imports.show', $import))
            ->assertSessionHas('status', __('portal.inbound.messages.confirmed_pickup', ['count' => 5]));

        $import->refresh();
        $this->assertSame('imported', $import->status);
        $orders = Order::query()->withoutGlobalScopes()->where('client_id', $client->id)->orderBy('id')->get();
        $this->assertCount(5, $orders);
        $this->assertSame(['pickup_deliver'], $orders->pluck('order_type')->unique()->all());
        $this->assertSame(['received'], $orders->pluck('operational_status')->unique()->all());
        $this->assertSame([$requested], $orders->map(fn (Order $order) => $order->requested_date?->toDateString())->unique()->all());
        $this->assertCount(1, $orders->pluck('pickup_address')->map(fn ($p) => json_encode($p))->unique());
        $this->assertEquals(['name' => 'Test Shipper', 'phone' => '02 9000 0000', 'address' => '5 Depot Rd', 'suburb' => 'Alexandria', 'state' => 'NSW', 'postcode' => '2015'], $orders->first()->pickup_address, 'every order carries the list\'s pickup address in the order form\'s shape');
        $this->assertSame(1, $orders->pluck('job_id')->unique()->count(), 'one Job per submission');
        $this->assertSame('transport_only', DB::table('jobs')->where('id', $orders->first()->job_id)->value('job_type'), 'a 提货直送 list opens a transport_only Job, not a loose one');

        $a = $orders->firstWhere('consignment_mark', 'CW1001');
        $this->assertNotNull($a);
        $this->assertSame('residential', $a->deliver_to_address_type);
        $this->assertSame([[1, 12.0], [1, 30.0]], $a->declaredPackages()->orderBy('id')->get()->map(fn ($p) => [(int) $p->qty, (float) $p->weight_kg])->all(), 'one declared package per carton with its own weight');
        $this->assertSame(2, $a->lines()->count(), 'the goods lines are kept too (waybill in front of each description)');
        $this->assertTrue($a->fresh()->tailgate_required, 'a residential consignee with a 30 kg carton reaches the tailgate rule');
        $this->assertSame(0, DB::table('asns')->where('client_id', $client->id)->count());

        // The result page and the list name the type; 待建预报 never sees these orders (pickup_deliver, no ASN).
        $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()->assertSee(__('portal.inbound.after_confirm_pickup'))->assertSee($a->order_no);
        $this->actingAs($user)->get(route('portal.asns.imports.index'))->assertOk()->assertSee(__('orders.types.pickup_deliver'));
        $this->assertSame(0, app(OrderInboundService::class)->candidates((int) $client->id)->count());
    }

    public function test_a_pickup_deliver_carton_without_a_weight_is_refused_and_blocks_its_order(): void
    {
        Storage::fake('local');
        $user = $this->clientUser($this->client(['name' => 'Bulk Client']));
        // The parser requires a positive weight on every row of every list (the per-piece weight bands the carton pick and the freight);
        // a 提货直送 list is no exception: the refused carton blocks its order and the preview says why.
        $csv = "ChannelWaybillNumber,Recipient,Recipient's Phone Number,Postal Code,State/Province,City,Detailed Address,Commodity,商品数量,Length(cm),Width(cm),Height(cm),Weight(kg)\n"
            ."CW2001-1,Test Recipient F,0412000006,2000,NSW,Sydney,10 Sample St,测试商品F,2,40,30,20,8\n"
            ."CW2002-1,Test Recipient G,0412000007,3000,VIC,Melbourne,11 Sample St,测试商品G,1,40,30,20,\n";

        $this->actingAs($user)->post(route('portal.asns.imports.store'), [
            'order_type' => 'pickup_deliver', 'manifest' => UploadedFile::fake()->createWithContent('list.csv', $csv),
            'requested_date' => today()->addDays(3)->toDateString(), 'pickup' => $this->pickupParty(),
        ])->assertSessionHasNoErrors();
        $import = OrderImport::query()->sole();
        $this->assertSame('pickup_deliver', $import->orderType());
        $this->assertSame(['CW2001-1'], collect($import->errors['groups'])->where('status', 'ready')->pluck('consignment_mark')->all(), 'the carton with a weight is ready');
        $this->assertSame([3], array_column($import->errors['issues'], 'row'), 'the carton without one is refused by row');
        $this->assertSame(1, $import->error_count);
        $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()
            ->assertSee('第 3 行「Weight(kg)」必须是大于 0 的数字')->assertSee(__('portal.inbound.ready_count', ['ready' => 1, 'blocked' => 0, 'errors' => 1]));
    }

    public function test_a_from_stock_list_from_the_new_order_page_is_the_inbound_list_it_always_was(): void
    {
        Storage::fake('local');
        $client = $this->client(['name' => 'Bulk Client']);
        $user = $this->clientUser($client);

        $this->actingAs($user)->post(route('portal.asns.imports.store'), [
            'order_type' => 'from_stock', 'manifest' => $this->upload(), 'container_no' => 'cosu0000001', 'container_size' => '40',
            // A pickup block posted by mistake is ignored for this type.
            'pickup' => $this->pickupParty(), 'requested_date' => '2020-01-01',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $import = OrderImport::query()->sole();
        $this->assertSame('from_stock', $import->errors['context']['order_type']);
        $this->assertSame('COSU0000001', $import->errors['context']['inbound']['container_no']);
        $this->assertArrayNotHasKey('pickup', $import->errors['context']);
        $this->assertSame(today()->addDays(7)->toDateString(), $import->errors['context']['requested_date'], '预计到港日 + 7 (no 到港日 → today + 7), never the pickup block\'s date');

        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import))->assertRedirect();
        $orders = Order::query()->withoutGlobalScopes()->where('client_id', $client->id)->get();
        $this->assertSame(['from_stock'], $orders->pluck('order_type')->unique()->all());
        $this->assertSame([null], $orders->pluck('pickup_address')->unique()->all());
        $this->assertSame('loose', DB::table('jobs')->where('id', $orders->first()->job_id)->value('job_type'));
        $this->assertGreaterThan(0, app(OrderInboundService::class)->candidates((int) $client->id)->count(), 'the orders wait in 待建预报 as before');
    }
}
