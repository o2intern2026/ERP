<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** B2 / B3 / B12: ASN with container, receiving with pallet classes, putaway completion event, devanning + labour tasks. */
class InboundTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_container_asn_gets_a_job_containers_and_line_counts(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();

        $asn = app(AsnService::class)->create([
            'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'reference' => 'COSU6508115030',
            'containers' => [['container_no' => 'COSU6508115030', 'size' => '40', 'unpack_mode' => 'loose', 'gross_weight_kg' => 18200]],
        ]);
        app(AsnService::class)->addLines($asn, [
            ['container_no' => 'COSU6508115030', 'consignment_mark' => 'GD20260506BC', 'description' => 'vacuum cleaner motor', 'expected_cartons' => 1],
            ['container_no' => 'COSU6508115030', 'consignment_mark' => 'GD20260506BC', 'description' => 'backpack vacuum cleaner', 'expected_cartons' => 1],
            ['container_no' => 'COSU6508115030', 'consignment_mark' => 'JJ26051603', 'description' => 'lighting fixture', 'expected_cartons' => 1],
        ]);

        $this->assertMatchesRegularExpression('/^ASN-\d{8}-0001$/', $asn->asn_no);
        $this->assertDatabaseHas('jobs', ['id' => $asn->job_id, 'client_id' => $client->id, 'job_type' => 'container', 'reference' => 'COSU6508115030']);
        $this->assertSame(3, $asn->containers()->first()->fresh()->line_count);

        // §4.7 #14: a loose-truck ASN has no container record.
        $truck = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $this->assertSame(0, $truck->containers()->count());
        $this->assertSame('loose', $truck->job->job_type);
    }

    public function test_receiving_suggests_pallet_class_from_the_client_thresholds(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => 'TEST1', 'size' => '20', 'unpack_mode' => 'pallet']]]);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Pallets', 'expected_cartons' => 60]]);

        $units = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => 60, 'units' => [
            ['unit_type' => 'pallet', 'carton_qty' => 20, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1600, 'weight_kg' => 600, 'pallet_source' => 'chep'],   // §4.7 #19
            ['unit_type' => 'pallet', 'carton_qty' => 20, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400, 'weight_kg' => 900, 'pallet_source' => 'warehouse_plain'],
            ['unit_type' => 'pallet', 'carton_qty' => 20, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400, 'weight_kg' => 500, 'pallet_source' => 'client_own', 'pallet_class' => 'oversize_wide', 'pallet_class_reason' => 'overhang'],
        ]], $this->location($warehouse, 'receiving'));

        $this->assertSame('oversize_high', $units[0]->pallet_class);
        $this->assertSame('overweight', $units[1]->pallet_class);
        $this->assertSame('oversize_wide', $units[2]->pallet_class);
        $this->assertSame('overhang', $units[2]->pallet_class_overridden_reason);
        $this->assertSame('receiving', $asn->fresh()->status);
        $this->assertDatabaseMissing('exceptions', ['source_type' => 'asn_line', 'source_id' => $line->id]);
    }

    public function test_putaway_of_the_last_unit_emits_asn_putaway_completed_with_the_billing_payload(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => 'CNT40', 'size' => '40', 'unpack_mode' => 'loose', 'gross_weight_kg' => 12000]]]);
        [$line] = app(AsnService::class)->addLines($asn, [['container_no' => 'CNT40', 'description' => 'Cartons', 'expected_cartons' => 30]]);
        $units = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => 30, 'units' => [
            ['unit_type' => 'pallet', 'carton_qty' => 20, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1300, 'weight_kg' => 400, 'pallet_source' => 'warehouse_plain'],
            ['unit_type' => 'carton', 'carton_qty' => 10],
        ]], $this->location($warehouse, 'receiving'));

        app(PutawayService::class)->putaway($units[0], $this->location($warehouse, 'storage'));
        $this->assertDatabaseMissing('outbox_events', ['event_name' => 'asn.putaway_completed']);

        app(PutawayService::class)->putaway($units[1], $this->location($warehouse, 'pickface'));

        $this->assertSame('putaway', $asn->fresh()->status);
        $event = OutboxEvent::query()->where('event_name', 'asn.putaway_completed')->firstOrFail();
        $this->assertSame($asn->job_id, $event->job_id);
        $this->assertSame($asn->asn_no, $event->correlation_id);
        $p = $event->payload;
        $this->assertSame(1, $p['pallet_count']);
        $this->assertSame('warehouse_plain', $p['pallets'][0]['pallet_source']);
        $this->assertSame('standard', $p['pallets'][0]['pallet_class']);
        $this->assertSame(2, $p['label_count']);
        $this->assertSame(1, $p['carton_unit_count']);
        $this->assertSame('CNT40', $p['container']['container_no']);
        $this->assertSame('40', $p['container']['size']);
        $this->assertSame('loose', $p['container']['unpack_mode']);
        $this->assertSame(30, $p['lines'][0]['received_cartons']);
        $this->assertSame('pickface', $units[1]->fresh()->pallet_class);
    }

    public function test_unplanned_arrivals_need_confirmation_before_putaway(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel', 'unplanned' => true]);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Surprise', 'expected_cartons' => 0]]);
        [$unit] = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => 3, 'variance_reason' => 'unannounced', 'units' => [['unit_type' => 'carton', 'carton_qty' => 3]]], $this->location($warehouse, 'receiving'));

        try {
            app(PutawayService::class)->putaway($unit, $this->location($warehouse, 'storage'));
            $this->fail('putaway should be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unplanned', $e->getMessage());
        }

        app(AsnService::class)->confirmUnplanned($asn);
        app(PutawayService::class)->putaway($unit->fresh(), $this->location($warehouse, 'storage'));
        $this->assertTrue($unit->fresh()->putaway_completed);
    }

    public function test_completing_tasks_emits_task_completed_with_billing_fields(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => 'DEV20', 'size' => '20', 'unpack_mode' => 'loose', 'gross_weight_kg' => 9000]]]);
        $container = $asn->containers()->first();
        $tasks = app(TaskService::class);

        $devan = $tasks->create('devanning', ['job_id' => $asn->job_id, 'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'source_type' => 'container', 'source_id' => $container->id, 'asn_id' => $asn->id, 'container_id' => $container->id]);
        $tasks->complete($devan, ['billable_qty' => 1, 'billable_uom' => 'container'], $asn->asn_no);
        $labour = $tasks->create('labour', ['job_id' => $asn->job_id, 'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'source_type' => 'asn', 'source_id' => $asn->id, 'asn_id' => $asn->id]);
        $tasks->complete($labour, ['hours_business' => 2, 'hours_after_hours' => 1]);

        $this->assertMatchesRegularExpression('/^TSK-\d{8}-0001$/', $devan->task_no);
        $events = OutboxEvent::query()->where('event_name', 'task.completed')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame('devanning', $events[0]->payload['task_type']);
        $this->assertEquals(['size' => '20', 'unpack_mode' => 'loose', 'line_count' => 0, 'gross_weight_kg' => 9000], $events[0]->payload['container']);
        $this->assertEquals(1, $events[0]->payload['billable_qty']);
        $this->assertSame('container', $events[0]->payload['billable_uom']);
        $this->assertEquals(2, $events[1]->payload['hours_business']);
        $this->assertEquals(1, $events[1]->payload['hours_after_hours']);
        $this->assertSame($events[0]->event_id, $devan->fresh()->billable_event_id);
        $this->assertSame('done', $devan->fresh()->status);
    }
}
