<?php

namespace Tests\Feature\Warehouse;

use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\Container;
use App\Modules\Warehouse\Models\PhysicalContainer;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\PhysicalContainerService;
use App\Modules\Warehouse\Services\ReceivingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * 物理柜 / 拼柜 (CHANGE_REQUESTS #122, lead 2026-09-14 — the seven defaults): one physical box shared by the container rows of
 * several ASNs / clients; the coordinator links them, the box carries the ONE devanning task, Warehouse computes the shares
 * (sum 1.0000, provisional on pre-advised cartons until receiving is complete, 重算分摊 → version + 1); the portal keeps showing
 * the client's own container number only.
 */
class PhysicalContainerTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private const BOX = 'MSKU1234567';

    /** One client's container ASN with one container row of the shared number and one goods line of $expected cartons. */
    private function memberAsn(Client $client, Warehouse $warehouse, int $expected, string $mode = 'loose', string $no = self::BOX): Asn
    {
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => $no, 'size' => '40', 'unpack_mode' => $mode]]]);
        app(AsnService::class)->addLines($asn, [['container_no' => $no, 'consignment_mark' => 'MK-'.$client->code, 'description' => 'Goods of '.$client->name, 'expected_cartons' => $expected]]);

        return $asn;
    }

    private function receiveAll(Asn $asn, Warehouse $warehouse, ?int $cartons = null): void
    {
        foreach ($asn->lines()->get() as $line) {
            $qty = $cartons ?? $line->expected_cartons;
            app(ReceivingService::class)->receiveLine($line, ['received_cartons' => $qty, 'units' => [['unit_type' => 'carton', 'carton_qty' => $qty]]], $this->location($warehouse, 'receiving'));
        }
        $asn->update(['receiving_completed_at' => now()]);
    }

    public function test_coordinator_registers_a_box_links_rows_of_several_clients_and_the_consolidation_follows(): void
    {
        $cs = $this->staff('customer_service');
        $warehouse = $this->warehouse();
        $other = $this->warehouse('SYD');
        $a = $this->client(['name' => 'Alpha Imports']);
        $b = $this->client(['name' => 'Bravo Trading']);
        $asnA1 = $this->memberAsn($a, $warehouse, 250);
        $asnA2 = $this->memberAsn($a, $warehouse, 50, 'pallet');
        $asnB = $this->memberAsn($b, $warehouse, 150);
        $asnFar = $this->memberAsn($b, $other, 10); // same number, another warehouse — never a member of this box

        // The ASN page offers the shortcut (pre-filled create form) to the staff roles, never to an operator.
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asnA1))->assertOk()->assertSee(__('warehouse.asns.link_box'))->assertSee('physical-containers/create?container_no='.self::BOX, false);
        $this->actingAs($this->staff('warehouse_operator'))->get(route('warehouse.asns.show', $asnA1))->assertOk()->assertDontSee(__('warehouse.asns.link_box'));
        $this->actingAs($this->staff('warehouse_operator'))->get(route('warehouse.physical_containers.index'))->assertForbidden();

        // warehouse_id is required; the number is upper-cased.
        $this->actingAs($cs)->get(route('warehouse.physical_containers.create', ['container_no' => self::BOX, 'warehouse_id' => $warehouse->id, 'size' => '40', 'unpack_mode' => 'loose']))->assertOk()->assertSee(self::BOX);
        $this->actingAs($cs)->post(route('warehouse.physical_containers.store'), ['container_no' => self::BOX, 'size' => '40', 'unpack_mode' => 'loose'])->assertSessionHasErrors('warehouse_id');
        $this->actingAs($cs)->post(route('warehouse.physical_containers.store'), ['container_no' => strtolower(self::BOX), 'warehouse_id' => $warehouse->id, 'size' => '40', 'unpack_mode' => 'loose', 'gross_weight_kg' => 18200, 'cartage_by_us' => 1, 'sideloader_required' => 1])->assertRedirect();
        $box = PhysicalContainer::query()->sole();
        $this->assertSame([self::BOX, 'fcl', 'expected', 'cartons_received', true, true, 0], [$box->container_no, $box->consolidation, $box->status, $box->allocation_basis, $box->cartage_by_us, $box->sideloader_required, $box->allocation_version]);
        $this->actingAs($cs)->post(route('warehouse.physical_containers.store'), ['container_no' => self::BOX, 'warehouse_id' => $warehouse->id, 'size' => '40', 'unpack_mode' => 'loose'])->assertSessionHasErrors('container_no'); // still open → refused
        $this->actingAs($cs)->get(route('warehouse.physical_containers.index'))->assertOk()->assertSee(self::BOX);

        // The picker lists the unlinked rows of the same number in the same warehouse (any client), not the other warehouse's.
        $show = $this->actingAs($cs)->get(route('warehouse.physical_containers.show', $box))->assertOk()->assertSee($asnA1->asn_no)->assertSee($asnB->asn_no)->assertDontSee($asnFar->asn_no);
        $show->assertSee('Alpha Imports')->assertSee('Bravo Trading');

        // Two rows of the same client → still FCL; a second client → LCL; a row of another warehouse → refused; twice → refused.
        $rowA1 = $asnA1->containers()->sole();
        $rowA2 = $asnA2->containers()->sole();
        $rowB = $asnB->containers()->sole();
        $rowFar = $asnFar->containers()->sole();
        $this->actingAs($cs)->post(route('warehouse.physical_containers.link', $box), ['container_ids' => [$rowA1->id, $rowA2->id]])->assertRedirect(route('warehouse.physical_containers.show', $box))->assertSessionHasNoErrors();
        $this->assertSame('fcl', $box->fresh()->consolidation);
        $this->assertSame($box->id, $rowA1->fresh()->physical_container_id);
        $this->actingAs($cs)->post(route('warehouse.physical_containers.link', $box), ['container_ids' => [$rowB->id]])->assertSessionHasNoErrors();
        $this->assertSame('lcl', $box->fresh()->consolidation);
        $this->actingAs($cs)->post(route('warehouse.physical_containers.link', $box), ['container_ids' => [$rowFar->id]])->assertSessionHasErrors('link');
        $this->assertNull($rowFar->fresh()->physical_container_id);
        $second = app(PhysicalContainerService::class)->create(['container_no' => 'TEMU7654321', 'warehouse_id' => $warehouse->id, 'size' => '40', 'unpack_mode' => 'loose']);
        $this->actingAs($cs)->post(route('warehouse.physical_containers.link', $second), ['container_ids' => [$rowB->id]])->assertSessionHasErrors('link'); // already in the first box
        $this->assertSame($box->id, $rowB->fresh()->physical_container_id);

        // A palletised member in a loose box is only a hint (it pays its own mode); the ASN page shows the box and its badge.
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asnA2))->assertOk()->assertSee(route('warehouse.physical_containers.show', $box))->assertSee(__('warehouse.physical_containers.consolidations.lcl'))->assertSee(__('warehouse.asns.box_devanning_hint'));

        // Unlink while nothing is billed: allowed, consolidation follows.
        $this->actingAs($cs)->post(route('warehouse.physical_containers.unlink', [$box, $rowB]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($rowB->fresh()->physical_container_id);
        $this->assertSame('fcl', $box->fresh()->consolidation);
        $this->actingAs($cs)->post(route('warehouse.physical_containers.unlink', [$box, $rowFar]))->assertSessionHasErrors('link'); // not a member

        // Global search finds the box for the staff roles.
        $this->actingAs($cs)->get(route('platform.search', ['q' => self::BOX]))->assertOk()->assertSee(route('warehouse.physical_containers.show', $box));
    }

    public function test_box_level_devanning_task_splits_the_box_by_received_cartons_and_the_portal_never_leaks_other_members(): void
    {
        $cs = $this->staff('customer_service');
        $supervisor = $this->staff('warehouse_supervisor');
        $warehouse = $this->warehouse();
        $a = $this->client(['name' => 'Alpha Imports']);
        $b = $this->client(['name' => 'Bravo Trading']);
        $c = $this->client(['name' => 'Charlie Goods']);
        $asnA = $this->memberAsn($a, $warehouse, 250);
        $asnB = $this->memberAsn($b, $warehouse, 150);
        $asnC = $this->memberAsn($c, $warehouse, 100);
        foreach ([$asnA, $asnB, $asnC] as $asn) {
            $this->receiveAll($asn, $warehouse);
        }
        $boxes = app(PhysicalContainerService::class);
        $box = $boxes->create(['container_no' => self::BOX, 'warehouse_id' => $warehouse->id, 'size' => '40', 'unpack_mode' => 'loose', 'gross_weight_kg' => 18200]);
        $boxes->link($box, Container::query()->where('container_no', self::BOX)->pluck('id')->all());
        $this->assertSame('lcl', $box->fresh()->consolidation);

        // A devanning task on a LINKED row through the ASN path is refused: devanning is registered once, on the box.
        $this->actingAs($supervisor)->post(route('warehouse.tasks.store'), ['asn_id' => $asnA->id, 'container_id' => $asnA->containers()->value('id'), 'task_type' => 'devanning'])->assertSessionHasErrors('container_id');
        $this->actingAs($supervisor)->post(route('warehouse.tasks.store'), ['physical_container_id' => $box->id, 'task_type' => 'wrap'])->assertSessionHasErrors('task_type');
        $this->actingAs($supervisor)->get(route('warehouse.tasks.create', ['physical_container_id' => $box->id]))->assertOk()->assertSee(self::BOX);
        $this->assertSame(0, WarehouseTask::query()->count());

        // The box page's 登记拆柜 creates the ONE task without Job / client; a second one is refused.
        $this->actingAs($supervisor)->post(route('warehouse.tasks.store'), ['physical_container_id' => $box->id, 'task_type' => 'devanning'])->assertRedirect(route('warehouse.physical_containers.show', $box))->assertSessionHasNoErrors();
        $task = WarehouseTask::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['devanning', 'physical_container', $box->id, $box->id, null, null, $warehouse->id, 'pending'], [$task->task_type, $task->source_type, $task->source_id, $task->physical_container_id, $task->job_id, $task->client_id, $task->warehouse_id, $task->status]);
        $this->assertSame($task->id, $box->fresh()->devanning_task_id);
        $this->actingAs($supervisor)->post(route('warehouse.tasks.store'), ['physical_container_id' => $box->id, 'task_type' => 'devanning'])->assertSessionHasErrors('physical_container_id');
        $this->actingAs($supervisor)->get(route('warehouse.tasks.index'))->assertOk()->assertSee($task->task_no)->assertSee(self::BOX);

        // Completing it from the task list: shares 0.5 / 0.3 / 0.2 (Σ = 1.0000) in the event, stored on the member rows, box devanned.
        $this->actingAs($supervisor)->post(route('warehouse.tasks.complete', $task))->assertRedirect()->assertSessionHasNoErrors();
        $task->refresh();
        $this->assertSame(['done', 1.0, 'container'], [$task->status, (float) $task->billable_qty, $task->billable_uom]);
        $event = OutboxEvent::query()->where('event_name', 'task.completed')->sole();
        $this->assertNull($event->job_id);
        $this->assertNull($event->client_id);
        $this->assertSame(self::BOX, $event->correlation_id);
        $p = $event->payload;
        $this->assertSame($box->id, $p['physical_container_id']);
        $this->assertEquals(['size' => '40', 'unpack_mode' => 'loose', 'line_count' => 3, 'gross_weight_kg' => 18200], $p['container']); // the JSON column normalises key order and 18200.0
        $this->assertSame('lcl', $p['physical_container']['consolidation']);
        $this->assertSame(500, $p['physical_container']['cartons_received_total']);
        $this->assertEquals(['cartons_received', false, 1, 500], [$p['allocation_basis'], $p['basis_provisional'], $p['activity_version'], $p['basis_total']]);
        $members = collect($p['members'])->keyBy('client_id');
        $this->assertSame([0.5, 0.3, 0.2], [$members[$a->id]['share'], $members[$b->id]['share'], $members[$c->id]['share']]);
        $this->assertSame([$asnA->job_id, $asnB->job_id, $asnC->job_id], [$members[$a->id]['job_id'], $members[$b->id]['job_id'], $members[$c->id]['job_id']]);
        $this->assertEqualsWithDelta(1.0, collect($p['members'])->sum('share'), 0.00001);
        $this->assertSame('0.3000', (string) $asnB->containers()->sole()->fresh()->devanning_share);
        $this->assertSame(['devanned', 1, $task->id], [$box->fresh()->status, $box->fresh()->allocation_version, $box->fresh()->devanning_task_id]);

        // Once devanned a member cannot leave — Finance reverses and the box is re-split instead; the box page says so.
        $this->actingAs($cs)->post(route('warehouse.physical_containers.unlink', [$box, $asnC->containers()->sole()]))->assertSessionHasErrors('link');
        $this->assertSame($box->id, $asnC->containers()->sole()->fresh()->physical_container_id);
        $this->actingAs($cs)->get(route('warehouse.physical_containers.show', $box))->assertOk()->assertSee(__('warehouse.physical_containers.unlink_locked'))->assertSee('50.00 %')->assertSee('30.00 %')->assertSee('20.00 %');
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asnB))->assertOk()->assertSee($task->task_no)->assertSee(__('warehouse.asns.box_share', ['share' => '30.00']));

        // F7 — the portal shows the client's own container number only: never the consolidation badge, never another member's ASN.
        $portal = $this->actingAs($this->clientUser($b))->get(route('portal.asns.show', $asnB))->assertOk()->assertSee(self::BOX)->assertSee($asnB->asn_no);
        $portal->assertDontSee($asnA->asn_no)->assertDontSee($asnC->asn_no)->assertDontSee('Alpha Imports')->assertDontSee('Charlie Goods')
            ->assertDontSee(__('warehouse.physical_containers.consolidations.lcl'))->assertDontSee(__('warehouse.physical_containers.consolidations.fcl'))->assertDontSee('physical-containers');
        $this->actingAs($this->clientUser($b))->get(route('warehouse.physical_containers.show', $box))->assertForbidden();
        $this->actingAs($this->clientUser($b))->get(route('portal.asns.index'))->assertOk()->assertSee($asnB->asn_no)->assertDontSee($asnA->asn_no);
    }

    public function test_shares_are_provisional_on_pre_advised_cartons_until_receiving_completes_and_recompute_emits_version_two(): void
    {
        $cs = $this->staff('customer_service');
        $this->actingAs($this->staff('warehouse_supervisor'));
        $warehouse = $this->warehouse();
        $a = $this->client();
        $b = $this->client();
        $asnA = $this->memberAsn($a, $warehouse, 300);
        $asnB = $this->memberAsn($b, $warehouse, 100);
        $boxes = app(PhysicalContainerService::class);
        $box = $boxes->create(['container_no' => self::BOX, 'warehouse_id' => $warehouse->id, 'size' => '20', 'unpack_mode' => 'loose']);
        $boxes->link($box, [$asnA->containers()->value('id'), $asnB->containers()->value('id')]);

        // Nothing emitted yet → nothing to recompute.
        $this->actingAs($cs)->post(route('warehouse.physical_containers.recompute', $box))->assertSessionHasErrors('recompute');

        // Devanned before the goods are counted: split on the pre-advised 300 / 100, flagged provisional.
        $task = $boxes->registerDevanning($box);
        $this->actingAs($this->staff('warehouse_supervisor'))->post(route('warehouse.tasks.complete', $task))->assertSessionHasNoErrors();
        $v1 = OutboxEvent::query()->where('event_name', 'task.completed')->sole()->payload;
        $this->assertEquals([true, 'cartons_received', 1, 400], [$v1['basis_provisional'], $v1['allocation_basis'], $v1['activity_version'], $v1['basis_total']]);
        $this->assertSame([0.75, 0.25], array_column($v1['members'], 'share'));
        $this->assertTrue($asnA->containers()->sole()->fresh()->devanning_basis_provisional);
        $this->actingAs($cs)->get(route('warehouse.physical_containers.show', $box))->assertOk()->assertSee(__('warehouse.physical_containers.provisional_badge'));

        // Receiving completes with a different reality (100 / 100) → 重算分摊 re-emits the same task with activity_version 2 and final shares.
        $this->receiveAll($asnA, $warehouse, 100);
        $this->receiveAll($asnB, $warehouse, 100);
        $this->actingAs($cs)->post(route('warehouse.physical_containers.recompute', $box))->assertRedirect()->assertSessionHasNoErrors();
        $events = OutboxEvent::query()->where('event_name', 'task.completed')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $v2 = $events[1]->payload;
        $this->assertEquals([$task->id, false, 2, 200], [$v2['task_id'], $v2['basis_provisional'], $v2['activity_version'], $v2['basis_total']]);
        $this->assertSame([0.5, 0.5], array_column($v2['members'], 'share'));
        $this->assertSame(2, $box->fresh()->allocation_version);
        $this->assertFalse($asnA->containers()->sole()->fresh()->devanning_basis_provisional);
        $this->assertSame(0, OutboxEvent::query()->where('event_name', 'physical_container.arrived')->count()); // never marked arrived → no cartage event to re-emit

        // The basis total of a pallet box with no pallets built yet is 0 → equal split, still provisional.
        $pallet = $boxes->create(['container_no' => 'PLTB0000001', 'warehouse_id' => $warehouse->id, 'size' => '40', 'unpack_mode' => 'pallet']);
        $this->assertSame('pallets', $pallet->allocation_basis); // F1: pallets when the box is palletised
        $asnP = $this->memberAsn($a, $warehouse, 40, 'pallet', 'PLTB0000001');
        $asnQ = $this->memberAsn($b, $warehouse, 20, 'pallet', 'PLTB0000001');
        $boxes->link($pallet, [$asnP->containers()->value('id'), $asnQ->containers()->value('id')]);
        $shares = $boxes->shares($pallet);
        $this->assertSame(['equal', true, [0.5, 0.5]], [$shares['basis'], $shares['provisional'], array_column($shares['members'], 'share')]);
    }
}
