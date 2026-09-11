<?php

namespace Tests\Feature\Platform;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Orders\Models\Order;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\GoodsReceipt;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\Wave;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `php artisan demo:run` (lead request 2026-09-11, CHANGE_REQUESTS #113): the automatic demo story runs through the real
 * services on a seeded database, is additive and repeatable (two runs → two independent sets), stops where --until says
 * and refuses bad input in Chinese. Tests have no Karrio key and no Edward client card (no own-fleet rate), so both
 * transport legs use the coordinator's manual quote and the first order is delivered with a carrier POD.
 */
class DemoRunCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_packed_story_and_a_second_run_does_not_collide(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Artisan::output() rather than expectsOutputToContain(): several substrings share one line (the tag is in the ASN line).
        $exit = Artisan::call('demo:run', ['--until' => 'packed', '--orders' => 2, '--lines' => 3, '--tag' => 'DEMO-T1']);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        foreach (['DEMO-T1', '✓ 预报单', '✓ 收货', '✓ 上架', '✓ 生成派送订单', '✓ 确认订单', '✓ 释放波次', '✓ 拣货', '✓ 打包', '演示数据已生成 · 标签 DEMO-T1', '停在「已打包」', config('app.url').'/warehouse/outbound'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
        $this->assertStringNotContainsString('✗', $output);
        $this->assertStringNotContainsString('✓ 运输报价与订舱', $output); // stopped at --until=packed

        $asn = $this->asnFor('DEMO-T1');
        $this->assertSame('putaway', $asn->status);
        $this->assertSame(3, $asn->lines()->count());
        $this->assertSame(['DEMO-T1-M1', 'DEMO-T1-M2', 'DEMO-T1-M1'], $asn->lines()->orderBy('id')->pluck('consignment_mark')->all()); // 3 lines cycle over 2 marks
        $receipt = GoodsReceipt::query()->withoutGlobalScopes()->where('asn_id', $asn->id)->firstOrFail();
        $this->assertSame('completed', $receipt->status);
        $this->assertDatabaseHas('documents', ['id' => $receipt->pdf_document_id, 'type' => 'goods_receipt', 'related_id' => $asn->id]);
        $this->assertDatabaseHas('exceptions', ['type' => 'discrepancy', 'job_id' => $asn->job_id]); // one line short, one carton damaged

        $orders = Order::query()->withoutGlobalScopes()->where('consignment_mark', 'like', 'DEMO-T1-M%')->orderBy('id')->get();
        $this->assertCount(2, $orders);
        $this->assertSame(['packed', 'packed'], $orders->pluck('operational_status')->all());
        $this->assertStringContainsString('(DEMO-T1)', $orders[0]->deliver_to_name);
        $this->assertDatabaseHas('exceptions', ['type' => 'pick_short', 'order_id' => $orders[0]->id]); // first order picked one carton short
        $this->assertSame(1, Wave::query()->count());
        $this->assertSame(2, Shipment::query()->whereIn('order_id', $orders->pluck('id'))->whereNotNull('fulfilment_id')->count()); // final quotes requested

        // Second run, other tag: a second independent set, no unique-key collisions.
        $this->artisan('demo:run', ['--until' => 'packed', '--orders' => 2, '--lines' => 3, '--tag' => 'DEMO-T2'])->assertExitCode(0);
        $this->assertNotSame($asn->id, $this->asnFor('DEMO-T2')->id);
        $this->assertSame(2, Order::query()->withoutGlobalScopes()->where('consignment_mark', 'like', 'DEMO-T2-M%')->where('operational_status', 'packed')->count());
        $this->assertSame(4, Order::query()->withoutGlobalScopes()->where('operational_status', 'packed')->count());
        $this->assertSame(2, Wave::query()->count());
    }

    public function test_it_runs_the_whole_story_to_the_invoice_and_prints_json(): void
    {
        $this->seed(DatabaseSeeder::class);

        $exit = Artisan::call('demo:run', ['--until' => 'invoiced', '--orders' => 2, '--lines' => 3, '--tag' => 'DEMO-FULL', '--json' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($result['ok'], $output);
        $this->assertSame('DEMO-FULL', $result['tag']);
        $this->assertNull($result['stopped_at']);
        $this->assertCount(12, $result['steps']);
        $this->assertSame([], array_filter($result['steps'], fn (array $s) => ! $s['ok']));

        $summary = $result['summary'];
        $this->assertSame('putaway', $summary['asn']['status']);
        $this->assertSame('delivered', $summary['orders'][0]['status']); // leg 1: manual quote → booked → dispatched → carrier POD
        $this->assertSame('packed', $summary['orders'][1]['status']);    // leg 2: booked only — left for the testers on purpose
        $this->assertSame('manual', $summary['shipments'][0]['source']);
        $this->assertSame('delivered', $summary['shipments'][0]['status']);
        $this->assertSame('booked', $summary['shipments'][1]['status']);
        $this->assertSame('completed', $summary['wave']['status']);
        $this->assertDatabaseHas('pods', ['shipment_id' => $summary['shipments'][0]['id']]);
        $this->assertDatabaseHas('outbound_dispatches', ['shipment_id' => $summary['shipments'][0]['id']]);

        $invoice = Invoice::query()->withoutGlobalScopes()->where('invoice_no', $summary['invoice']['invoice_no'])->firstOrFail();
        $this->assertSame('issued', $invoice->status);
        $this->assertGreaterThan(0, $invoice->total_cents);
        $this->assertSame((int) $invoice->total_cents, $summary['invoice']['total_cents']);

        $this->assertNotEmpty($summary['notes']);
        $this->assertNotEmpty($summary['next']);
        foreach ($summary['next'] as $page) {
            $this->assertStringStartsWith((string) config('app.url'), $page['url']);
        }
    }

    public function test_until_asn_stops_after_the_asn(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('demo:run', ['--until' => 'asn', '--tag' => 'DEMO-ASN'])
            ->expectsOutputToContain('DEMO-ASN')
            ->expectsOutputToContain('停在「预报单」')
            ->assertExitCode(0);

        $asn = $this->asnFor('DEMO-ASN');
        $this->assertSame('arrived', $asn->status);
        $this->assertSame(6, $asn->lines()->count());
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, GoodsReceipt::query()->withoutGlobalScopes()->count());
    }

    public function test_until_putaway_leaves_the_last_unit_for_the_tester(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('demo:run', ['--until' => 'putaway', '--orders' => 2, '--lines' => 3, '--tag' => 'DEMO-PUT'])
            ->expectsOutputToContain('留在收货区')
            ->assertExitCode(0);

        $asn = $this->asnFor('DEMO-PUT');
        $this->assertSame('receiving', $asn->status);
        $units = StockUnit::query()->withoutGlobalScopes()->whereIn('asn_line_id', $asn->lines()->select('id'))->get();
        $this->assertSame(1, $units->where('putaway_completed', false)->count());
        $this->assertSame('good', $units->firstWhere('putaway_completed', false)->condition);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_bad_input_is_refused_in_chinese_with_exit_code_one(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('demo:run', ['--until' => 'nowhere'])->expectsOutputToContain('未知的 --until 阶段「nowhere」')->assertExitCode(1);
        $this->artisan('demo:run', ['--until' => 'asn', '--lines' => 1])->expectsOutputToContain('--lines 至少为 2')->assertExitCode(1);
        $this->artisan('demo:run', ['--until' => 'asn', '--lines' => 2, '--orders' => 3])->expectsOutputToContain('--orders 必须在 1 与 --lines')->assertExitCode(1);
        $this->artisan('demo:run', ['--until' => 'asn', '--client' => 'NOPE'])->expectsOutputToContain('客户代码「NOPE」不存在')->assertExitCode(1);
        $this->artisan('demo:run', ['--until' => 'asn', '--warehouse' => 'SYD'])->expectsOutputToContain('仓库代码「SYD」不存在')->assertExitCode(1);
        $this->assertSame(0, Asn::query()->withoutGlobalScopes()->count());
    }

    private function asnFor(string $containerNo): Asn
    {
        return Asn::query()->withoutGlobalScopes()->whereHas('containers', fn ($q) => $q->where('container_no', $containerNo))->firstOrFail();
    }
}
