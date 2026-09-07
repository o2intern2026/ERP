<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\ClientAddress;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** A4 acceptance §3.8 #1/#2: grouping, readable blockers, audit and idempotent retransmission. */
class OrderImportTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_real_workbook_creates_the_expected_grouped_orders_and_keeps_fba_reference(): void
    {
        $user = $this->staff('customer_service');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose');
        $path = base_path('data/需派送货物清单.xlsx');

        $this->actingAs($user)->post(route('orders.imports.preview'), [
            'client_id' => $client->id,
            'job_id' => $job['job_id'],
            'requested_date' => '2026-09-30',
            'service_level' => 'standard',
            'manifest' => new UploadedFile($path, '需派送货物清单.xlsx', null, null, true),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $import = OrderImport::query()->sole();
        $ready = collect($import->errors['groups'])->where('status', 'ready');
        $this->assertCount(30, $ready);
        $this->assertSame(5, $import->error_count);

        $this->actingAs($user)->post(route('orders.imports.confirm', $import), [
            'groups' => $ready->pluck('key')->all(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(30, Order::query()->count());
        $this->assertDatabaseCount('order_lines', 135);
        $this->assertTrue(Order::query()->whereNotNull('fba_reference')->exists());
        $this->assertSame($job['job_id'], Order::query()->firstOrFail()->job_id);
    }

    public function test_preview_groups_valid_lines_blocks_inconsistent_mark_and_confirms_one_order(): void
    {
        $user = $this->staff('customer_service');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose');
        ClientAddress::query()->create([
            'client_id' => $client->id, 'label' => 'Amazon BWU2', 'contact_name' => 'Amazon BWU2',
            'address' => '1 Warehouse Way, Kemps Creek NSW 2178', 'suburb' => 'Kemps Creek', 'state' => 'NSW', 'postcode' => '2178',
            'address_type' => 'fba', 'default_instructions' => '预约卸货。',
        ]);

        $response = $this->actingAs($user)->post(route('orders.imports.preview'), [
            'client_id' => $client->id,
            'job_id' => $job['job_id'],
            'requested_date' => '2026-09-30',
            'service_level' => 'standard',
            'manifest' => UploadedFile::fake()->createWithContent('dispatch.csv', $this->manifest()),
        ]);

        $import = OrderImport::query()->firstOrFail();
        $response->assertSessionHasNoErrors()->assertRedirect(route('orders.imports.show', $import));
        $this->assertSame('pending', $import->status);
        $this->assertSame(5, $import->row_count);
        $this->assertSame(3, $import->error_count);
        $this->assertNotNull($import->document_id);
        $this->assertSame('custom-value', $import->errors['raw_rows'][0]['raw_json']['Unknown column']);

        $ready = collect($import->errors['groups'])->firstWhere('status', 'ready');
        $blocked = collect($import->errors['groups'])->firstWhere('status', 'blocked');
        $this->assertSame([2, 3], $ready['row_numbers']);
        $this->assertSame('FBA-READY', $ready['fba_reference']);
        $this->assertNotNull($ready['client_address_id']);
        $this->assertStringContainsString('地址或 FBA 引用不一致', $blocked['message']);

        $this->actingAs($user)->get(route('orders.imports.show', $import))
            ->assertOk()->assertSee('FBA-READY')->assertSee('已阻断')->assertSee('预约卸货');

        $this->actingAs($user)->post(route('orders.imports.confirm', $import), [
            'groups' => [$ready['key']],
        ])->assertSessionHasNoErrors()->assertRedirect(route('orders.imports.show', $import));

        $order = Order::query()->with(['lines', 'declaredPackages'])->sole();
        $this->assertSame('excel', $order->source);
        $this->assertSame($job['job_id'], $order->job_id);
        $this->assertSame('FBA-READY', $order->fba_reference);
        $this->assertSame('fba', $order->deliver_to_address_type);
        $this->assertSame('预约卸货。', $order->delivery_instructions);
        $this->assertCount(2, $order->lines);
        $this->assertCount(2, $order->declaredPackages);
        $this->assertSame(3, $import->fresh()->errors['result']['failed_rows']);
        $this->assertSame('imported', $import->fresh()->status);
    }

    public function test_retransmitted_file_warns_duplicate_and_never_creates_a_second_order(): void
    {
        $user = $this->staff('customer_service');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose');
        $payload = [
            'client_id' => $client->id, 'job_id' => $job['job_id'], 'requested_date' => '2026-09-30',
            'service_level' => 'standard', 'manifest' => UploadedFile::fake()->createWithContent('dispatch.csv', $this->validOnlyManifest()),
        ];

        $this->actingAs($user)->post(route('orders.imports.preview'), $payload)->assertRedirect();
        $first = OrderImport::query()->firstOrFail();
        $key = collect($first->errors['groups'])->firstWhere('status', 'ready')['key'];
        $this->actingAs($user)->post(route('orders.imports.confirm', $first), ['groups' => [$key]])->assertRedirect();
        $this->assertSame(1, Order::query()->count());

        $payload['manifest'] = UploadedFile::fake()->createWithContent('dispatch.csv', $this->validOnlyManifest());
        $this->actingAs($user)->post(route('orders.imports.preview'), $payload)->assertRedirect();
        $second = OrderImport::query()->latest('id')->firstOrFail();
        $duplicate = collect($second->errors['groups'])->first();
        $this->assertSame('duplicate', $duplicate['status']);
        $this->assertStringContainsString('已有相同订单', $duplicate['message']);
        $this->assertStringContainsString('文件与导入', $second->errors['warnings'][0]['message']);

        $this->actingAs($user)->post(route('orders.imports.confirm', $second), ['groups' => [$duplicate['key']]])->assertRedirect();
        $this->assertSame(1, Order::query()->count());
        $this->actingAs($user)->get(route('orders.imports.errors', $second))
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertSee('重复订单');
    }

    private function manifest(): string
    {
        return implode("\n", [
            'Unknown column,Address,Mark,No.,Weight(kg),Consignee Name,Contact,State,Postcode,Chinese Name,Type of packaging,FBA Reference,External Ref',
            'custom-value,"1 Warehouse Way, Kemps Creek NSW 2178",READY,2,10,Amazon BWU2,0299999999,NSW,2178,展示架,carton,FBA-READY,PO-READY',
            'another,"1 Warehouse Way, Kemps Creek NSW 2178",READY,1,5,Amazon BWU2,0299999999,NSW,2178,配件,carton,FBA-READY,PO-READY',
            'x,"2 First Road, Sydney NSW 2000",CONFLICT,1,4,Receiver A,0400000001,NSW,2000,货物甲,carton,FBA-A,',
            'y,"3 Second Road, Sydney NSW 2000",CONFLICT,1,4,Receiver B,0400000002,NSW,2000,货物乙,carton,FBA-B,',
            'z,"4 Third Road, Sydney NSW 2000",INVALID,0,0,Receiver C,0400000003,NSW,2000,错误货物,carton,,',
        ]);
    }

    private function validOnlyManifest(): string
    {
        return implode("\n", [
            'Mark,No.,Weight(kg),Consignee Name,Address,State,Postcode,English Name,Type of packaging,FBA Reference',
            'DUPLICATE,1,5,Receiver,"9 Road, Sydney NSW 2000",NSW,2000,Goods,carton,FBA-DUP',
        ]);
    }
}
