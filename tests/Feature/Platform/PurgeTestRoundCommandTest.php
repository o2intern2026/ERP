<?php

namespace Tests\Feature\Platform;

use App\Modules\Orders\Models\OrderImport;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Models\Wave;
use App\Modules\Warehouse\Services\OutboundService;
use App\Support\Contracts\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** CHANGE_REQUESTS #157: erp:purge-test-round removes one test round (Jobs + imports and everything hanging off them); dry run by default; other data untouched. */
class PurgeTestRoundCommandTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_a_test_round_is_reported_on_a_dry_run_and_removed_with_force_while_another_clients_data_stays(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $other = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'PR1', 'cartons' => 8, 'weight_kg' => 40]]);
        ['asn' => $otherAsn, 'lines' => $otherLines] = $this->stockedAsn($other, $warehouse, [['mark' => 'KEEP1', 'cartons' => 4, 'weight_kg' => 20]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 3]]);
        $keep = $this->confirmedOrder($other, $otherAsn->job_id, [['asn_line_id' => $otherLines[0]->id, 'qty' => 2]]);
        $fulfilment = (int) DB::table('fulfilments')->where('order_id', $order->id)->value('id');
        $this->actingAs($operator)->post(route('warehouse.outbound.waves.release'), ['warehouse_id' => $warehouse->id, 'order_ids' => [$order->id]])->assertRedirect();
        foreach (WarehouseTask::query()->where('task_type', 'pick')->where('fulfilment_id', $fulfilment)->firstOrFail()->lines as $line) {
            app(OutboundService::class)->confirmPick($line, (int) $line->required_qty, $operator->id);
        }
        app(OutboundService::class)->pack($fulfilment, [['package_type' => 'carton', 'qty' => 3, 'weight_kg' => 5, 'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 150]], $operator->id);
        app(OutboundService::class)->dispatch($fulfilment, 0, 'client', null, $operator->id);
        $import = OrderImport::query()->create(['client_id' => $client->id, 'source' => 'portal', 'status' => 'imported', 'errors' => ['context' => ['job_id' => $asn->job_id], 'result' => ['created' => [['order_id' => $order->id, 'order_no' => $order->order_no]]]]]);
        Storage::disk('local')->put('imports/orders/round.csv', 'x');
        app(DocumentService::class)->attach('packing_list', 'order_import', $import->id, 'imports/orders/round.csv', ['client_id' => $client->id, 'original_name' => 'round.csv']);
        $jobs = $asn->job_id;
        $before = ['orders' => DB::table('orders')->count(), 'units' => DB::table('stock_units')->count(), 'jobs' => DB::table('jobs')->count(), 'events' => DB::table('outbox_events')->count()];
        $this->assertGreaterThan(0, DB::table('packages')->where('fulfilment_id', $fulfilment)->count());

        // Dry run: counts printed, nothing deleted, the file still there.
        $this->artisan('erp:purge-test-round', ['--jobs' => (string) $jobs, '--imports' => (string) $import->id])
            ->expectsOutputToContain('DRY RUN')->expectsOutputToContain('orders')->assertSuccessful();
        $this->assertSame($before['orders'], DB::table('orders')->count());
        $this->assertSame($before['units'], DB::table('stock_units')->count());
        Storage::disk('local')->assertExists('imports/orders/round.csv');

        // Force: the round is gone — orders, units, ledger, tasks, wave, packages, dispatch, receipts, ASN, import, document + file, events, job.
        $this->artisan('erp:purge-test-round', ['--jobs' => (string) $jobs, '--imports' => (string) $import->id, '--force' => true])->expectsOutputToContain('Deleted')->assertSuccessful();
        $this->assertSame(0, DB::table('orders')->where('job_id', $jobs)->count());
        $this->assertSame(0, DB::table('stock_units')->where('job_id', $jobs)->count());
        $this->assertSame(0, DB::table('warehouse_tasks')->where('job_id', $jobs)->count());
        $this->assertSame(0, Wave::query()->count(), 'the emptied wave is gone');
        $this->assertSame(0, DB::table('packages')->where('fulfilment_id', $fulfilment)->count());
        $this->assertSame(0, DB::table('outbound_dispatches')->where('fulfilment_id', $fulfilment)->count());
        $this->assertSame(0, DB::table('asns')->where('id', $asn->id)->count());
        $this->assertSame(0, DB::table('goods_receipts')->where('job_id', $jobs)->count());
        $this->assertSame(0, OrderImport::query()->whereKey($import->id)->count());
        $this->assertSame(0, DB::table('documents')->where('related_type', 'order_import')->where('related_id', $import->id)->count());
        Storage::disk('local')->assertMissing('imports/orders/round.csv');
        $this->assertSame(0, DB::table('outbox_events')->where('job_id', $jobs)->count());
        $this->assertSame(0, DB::table('jobs')->where('id', $jobs)->count());
        // The other client's round is intact.
        $this->assertSame(1, DB::table('orders')->where('id', $keep->id)->count());
        $this->assertSame(1, DB::table('asns')->where('id', $otherAsn->id)->count());
        $this->assertGreaterThan(0, DB::table('stock_units')->where('job_id', $otherAsn->job_id)->count());
        $this->assertSame($before['jobs'] - 1, DB::table('jobs')->count());
    }
}
