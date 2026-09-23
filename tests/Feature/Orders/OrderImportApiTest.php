<?php

namespace Tests\Feature\Orders;

use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\Services\OrderApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #145 自动导入 — `POST /orders/api/imports`: the client's system pushes a whole list (the #143 consolidation fixture,
 * one row = one carton) with the same bearer token as /orders/api/orders; options fall back to the client's import defaults; a clean
 * list is auto-confirmed only when asked, anything else stays pending in the portal; a replayed file returns the earlier import.
 */
class OrderImportApiTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const FIXTURE = 'tests/Fixtures/imports/consolidation_dsf.xlsx';

    private function file(string $name = 'consolidation.xlsx'): UploadedFile
    {
        return new UploadedFile(base_path(self::FIXTURE), $name, null, null, true);
    }

    private function token(Client $client): string
    {
        return app(OrderApiTokenService::class)->issue((int) $client->id, 'client system', null)['plain'];
    }

    public function test_requests_without_a_valid_token_or_for_an_inactive_client_are_rejected(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $pending = $this->client(['status' => 'pending']);

        $this->post(route('orders.api.imports.store'), ['manifest' => $this->file()])->assertStatus(401)->assertJson(['error' => 'unauthenticated']);
        $this->withToken('oak_wrong')->post(route('orders.api.imports.store'), ['manifest' => $this->file()])->assertStatus(401);
        $this->withToken($this->token($pending))->post(route('orders.api.imports.store'), ['manifest' => $this->file()])->assertStatus(403)->assertJson(['error' => 'client_inactive']);
        $this->withToken($this->token($client))->post(route('orders.api.imports.store'), [])->assertStatus(422)->assertJsonValidationErrors(['manifest']);
        $this->withToken($this->token($client))->getJson(route('orders.api.imports.show', 999))->assertStatus(404)->assertJson(['error' => 'not_found']);
        $this->assertSame(0, OrderImport::query()->count());
    }

    public function test_a_pushed_list_is_read_with_the_request_options_auto_confirmed_when_clean_and_visible_in_the_portal(): void
    {
        Storage::fake('local');
        $client = $this->client(['name' => 'Pushing Client']);
        $other = $this->client();
        $token = $this->token($client);

        $response = $this->withToken($token)->post(route('orders.api.imports.store'), [
            'manifest' => $this->file(), 'group_by' => 'recipient', 'address_type_default' => 'residential', 'auto_confirm' => '1', 'container_no' => 'cosu0000001', 'container_size' => '40',
        ]);
        $response->assertStatus(201)->assertJson(['status' => 'imported', 'auto_confirmed' => true, 'replayed' => false, 'source' => 'api', 'order_type' => 'from_stock', 'row_count' => 6, 'error_count' => 0])
            ->assertJsonPath('groups.total', 5)->assertJsonPath('groups.imported', 5)->assertJsonCount(5, 'orders')->assertJsonCount(0, 'issues')->assertJsonPath('file_name', 'consolidation.xlsx');
        $import = OrderImport::query()->sole();
        $this->assertSame(['api', 'imported'], [$import->source, $import->status]);
        $this->assertSame(['recipient', 'residential', 'COSU0000001', true], [
            $import->errors['context']['group_by'], $import->errors['context']['address_type_default'], $import->errors['context']['inbound']['container_no'], $import->errors['context']['automation']['auto_confirmed'],
        ]);
        $this->assertSame(route('portal.asns.imports.show', $import), $response->json('review_url'));
        $orders = Order::query()->withoutGlobalScopes()->where('client_id', $client->id)->get();
        $this->assertCount(5, $orders);
        $this->assertSame(['api'], $orders->pluck('source')->unique()->all(), 'orders.source = api');
        $this->assertSame(['from_stock'], $orders->pluck('order_type')->unique()->all());
        $this->assertSame('loose', DB::table('jobs')->where('id', $orders->first()->job_id)->value('job_type'));
        $this->assertSame(collect($response->json('orders'))->pluck('order_no')->sort()->values()->all(), $orders->pluck('order_no')->sort()->values()->all());

        // GET polls the same summary; the client sees the list in the portal (badge API 推送), another client does not.
        $this->withToken($token)->getJson(route('orders.api.imports.show', $import))->assertOk()->assertJson(['import_id' => $import->id, 'status' => 'imported', 'auto_confirmed' => true]);
        $this->actingAs($this->clientUser($client))->get(route('portal.asns.imports.index'))->assertOk()->assertSee(__('portal.inbound.sources.api'))->assertSee('consolidation.xlsx');
        $this->actingAs($this->clientUser($client))->get(route('portal.asns.imports.show', $import))->assertOk()->assertSee(__('portal.inbound.sources.api'));
        $this->actingAs($this->clientUser($other))->get(route('portal.asns.imports.show', $import))->assertNotFound();
        $this->withToken($this->token($other))->getJson(route('orders.api.imports.show', $import))->assertStatus(404);

        // The same file pushed again is a replay: nothing read twice, the earlier import answers.
        $this->withToken($token)->post(route('orders.api.imports.store'), ['manifest' => $this->file('again.xlsx'), 'auto_confirm' => '1'])
            ->assertStatus(200)->assertJson(['import_id' => $import->id, 'replayed' => true, 'status' => 'imported']);
        $this->assertSame(1, OrderImport::query()->count());

        // force=1 reads it anyway — and the duplicate protection blocks every group (same marks, consignees and date), so nothing is auto-confirmed.
        $forced = $this->withToken($token)->post(route('orders.api.imports.store'), ['manifest' => $this->file(), 'group_by' => 'recipient', 'auto_confirm' => '1', 'force' => '1']);
        $forced->assertStatus(202)->assertJson(['status' => 'pending', 'auto_confirmed' => false, 'replayed' => false])->assertJsonPath('groups.blocked', 5)->assertJsonCount(5, 'blocked');
        $this->assertSame(2, OrderImport::query()->count());
        $this->assertSame(5, Order::query()->withoutGlobalScopes()->where('client_id', $client->id)->count(), 'no duplicate orders');
    }

    public function test_a_list_with_a_refused_row_stays_pending_even_with_auto_confirm_until_the_client_reviews_it_in_the_portal(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $token = $this->token($client);
        $csv = "ChannelWaybillNumber,Recipient,Recipient's Phone Number,Postal Code,State/Province,City,Detailed Address,Commodity,商品数量,Length(cm),Width(cm),Height(cm),Weight(kg)\n"
            ."CW2001-1,Test Recipient F,0412000006,2000,NSW,Sydney,10 Sample St,测试商品F,2,40,30,20,8\n"
            ."CW2002-1,Test Recipient G,0412000007,3000,VIC,Melbourne,11 Sample St,测试商品G,1,40,30,20,\n";

        $response = $this->withToken($token)->post(route('orders.api.imports.store'), ['manifest' => UploadedFile::fake()->createWithContent('list.csv', $csv), 'auto_confirm' => '1']);
        $response->assertStatus(202)->assertJson(['status' => 'pending', 'auto_confirmed' => false, 'error_count' => 1])->assertJsonPath('groups.ready', 1)->assertJsonCount(1, 'issues')->assertJsonPath('issues.0.row', 3)->assertJsonCount(0, 'orders');
        $this->assertStringContainsString('必须是大于 0 的数字', $response->json('issues.0.message'));
        $import = OrderImport::query()->sole();
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->where('client_id', $client->id)->count(), 'auto-confirm never guesses');

        // The client reviews the pending list in the portal (the skipped row acknowledged) and confirms; the API then reports it imported.
        $this->actingAs($this->clientUser($client))->post(route('portal.asns.imports.confirm', $import), ['skip_acknowledged' => '1'])->assertRedirect(route('portal.asns.imports.show', $import));
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->where('client_id', $client->id)->count());
        $this->withToken($token)->getJson(route('orders.api.imports.show', $import))->assertOk()->assertJson(['status' => 'imported', 'auto_confirmed' => false])->assertJsonCount(1, 'orders');
    }

    public function test_the_client_defaults_apply_when_the_request_omits_the_options_and_a_pickup_deliver_list_needs_its_pickup_party(): void
    {
        Storage::fake('local');
        $client = $this->client(['import_defaults' => ['group_by' => 'recipient', 'address_type_default' => 'residential', 'auto_confirm' => true]]);
        $token = $this->token($client);

        $this->withToken($token)->post(route('orders.api.imports.store'), ['manifest' => $this->file()])->assertStatus(201)->assertJson(['auto_confirmed' => true])->assertJsonPath('groups.total', 5);
        $import = OrderImport::query()->sole();
        $this->assertSame(['recipient', 'residential'], [$import->errors['context']['group_by'], $import->errors['context']['address_type_default']]);
        $this->assertSame(['residential'], Order::query()->withoutGlobalScopes()->where('client_id', $client->id)->pluck('deliver_to_address_type')->unique()->all());

        // 现场提货直送 (CR #144) through the API: the pickup party and the delivery date are required; a fresh client so the marks are new.
        $pickupClient = $this->client();
        $pickupToken = $this->token($pickupClient);
        $this->withToken($pickupToken)->post(route('orders.api.imports.store'), ['manifest' => $this->file(), 'order_type' => 'pickup_deliver'])
            ->assertStatus(422)->assertJsonValidationErrors(['pickup', 'pickup.name', 'pickup.address', 'pickup.suburb', 'pickup.state', 'pickup.postcode']);
        $response = $this->withToken($pickupToken)->post(route('orders.api.imports.store'), [
            'manifest' => $this->file(), 'order_type' => 'pickup_deliver', 'group_by' => 'recipient', 'auto_confirm' => '1', 'requested_date' => today()->addDays(4)->toDateString(),
            'pickup' => ['name' => 'Test Shipper', 'phone' => '02 9000 0000', 'address' => '5 Depot Rd', 'suburb' => 'Alexandria', 'state' => 'NSW', 'postcode' => '2015'],
        ]);
        $response->assertStatus(201)->assertJson(['status' => 'imported', 'order_type' => 'pickup_deliver'])->assertJsonCount(5, 'orders');
        $orders = Order::query()->withoutGlobalScopes()->where('client_id', $pickupClient->id)->get();
        $this->assertSame(['pickup_deliver'], $orders->pluck('order_type')->unique()->all());
        $this->assertEquals(['name' => 'Test Shipper', 'phone' => '02 9000 0000', 'address' => '5 Depot Rd', 'suburb' => 'Alexandria', 'state' => 'NSW', 'postcode' => '2015'], $orders->first()->pickup_address);
        $this->assertSame([today()->addDays(4)->toDateString()], $orders->map(fn (Order $o) => $o->requested_date?->toDateString())->unique()->all());
        $this->assertSame('transport_only', DB::table('jobs')->where('id', $orders->first()->job_id)->value('job_type'));
        $this->assertSame(6, $orders->sum(fn (Order $o) => $o->declaredPackages()->count()), 'one declared package per carton');
    }
}
