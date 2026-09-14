<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\Billing\Services\ChargeEngine;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\Container;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\PhysicalContainerService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\TaskService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #122 billing arithmetic: a shared box's devanning fee is one charge per member Job at the MEMBER's rate × share
 * (per-client card, per-member unpack_mode, caps on the whole box), cartage / sideloader allocated the same way on the box's
 * arrival, idempotent on redelivery, and a higher allocation version reverses the whole previous split.
 */
class DevanningAllocationTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private const BOX = 'MSKU1234567';

    private function memberAsn(Client $client, Warehouse $warehouse, int $cartons, string $mode = 'loose'): Asn
    {
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => self::BOX, 'size' => '40', 'unpack_mode' => $mode]]]);
        [$line] = app(AsnService::class)->addLines($asn, [['container_no' => self::BOX, 'description' => 'Goods', 'expected_cartons' => $cartons]]);
        app(ReceivingService::class)->receiveLine($line, ['received_cartons' => $cartons, 'units' => [['unit_type' => 'carton', 'carton_qty' => $cartons]]], $this->location($warehouse, 'receiving'));
        $asn->update(['receiving_completed_at' => now()]);

        return $asn;
    }

    /** members[] of a synthetic box payload: [client_id, job_id, unpack_mode, share] each. */
    private function boxPayload(int $boxId, array $members, array $box = []): array
    {
        $rows = array_map(fn (array $m) => ['asn_id' => 1, 'asn_no' => 'ASN-X', 'container_id' => 1, 'container_no' => self::BOX, 'client_id' => $m[0], 'job_id' => $m[1], 'unpack_mode' => $m[2], 'line_count' => 5, 'cartons_expected' => 100, 'cartons_received' => 100, 'cbm' => 1.0, 'pallets' => 0, 'basis_qty' => 100.0, 'share' => $m[3]], $members);
        $box += ['size' => '40', 'unpack_mode' => 'loose', 'line_count' => 15, 'gross_weight_kg' => 15000.0, 'cartage_by_us' => true, 'sideloader_required' => false];

        return [
            'physical_container_id' => $boxId,
            'physical_container' => ['id' => $boxId, 'container_no' => self::BOX, 'size' => $box['size'], 'unpack_mode' => $box['unpack_mode'], 'gross_weight_kg' => $box['gross_weight_kg'], 'consolidation' => 'lcl', 'sideloader_required' => $box['sideloader_required'], 'cartage_by_us' => $box['cartage_by_us'], 'line_count_total' => $box['line_count'], 'members_count' => count($members)],
            'container' => ['size' => $box['size'], 'unpack_mode' => $box['unpack_mode'], 'line_count' => $box['line_count'], 'gross_weight_kg' => $box['gross_weight_kg']],
            'members' => $rows, 'allocation_basis' => 'cartons_received', 'basis_provisional' => false, 'basis_total' => 100.0 * count($members),
            'cartage_by_us' => $box['cartage_by_us'], 'sideloader_required' => $box['sideloader_required'], 'activity_version' => $box['activity_version'] ?? 1,
        ];
    }

    private function byJob(string $code): array
    {
        return Charge::query()->with('chargeCode')->whereHas('chargeCode', fn ($q) => $q->where('code', $code))->where('status', '!=', 'reversed')->whereNull('reversal_of_charge_id')
            ->orderBy('job_id')->get()->mapWithKeys(fn (Charge $c) => [$c->job_id => $c->amount_cents])->all();
    }

    public function test_three_members_share_the_edward_40_loose_devanning_fee_by_received_cartons_each_on_its_own_job(): void
    {
        $warehouse = $this->warehouse();
        $a = $this->client();
        $b = $this->client();
        $c = $this->client();
        $asnA = $this->memberAsn($a, $warehouse, 250);
        $asnB = $this->memberAsn($b, $warehouse, 150);
        $asnC = $this->memberAsn($c, $warehouse, 100);
        $boxes = app(PhysicalContainerService::class);
        $box = $boxes->create(['container_no' => self::BOX, 'warehouse_id' => $warehouse->id, 'size' => '40', 'unpack_mode' => 'loose', 'gross_weight_kg' => 18200]);
        $boxes->link($box, Container::query()->where('container_no', self::BOX)->pluck('id')->all());
        $task = $boxes->registerDevanning($box);
        app(TaskService::class)->complete($task, ['billable_qty' => 1, 'billable_uom' => 'container']);

        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue(); // redelivery → nothing new
        $event = OutboxEvent::query()->where('event_name', 'task.completed')->sole();
        app(ChargeEngine::class)->applyEvent($event->envelope()); // the same version replayed by hand → the existing charges, nothing new

        // Edward WH-DEVAN-40-LOOSE 550.00 × 0.5 / 0.3 / 0.2 — one charge per member Job / client, keyed task:{id}:job:{job}, source = the box.
        $this->assertSame([$asnA->job_id => 27500, $asnB->job_id => 16500, $asnC->job_id => 11000], $this->byJob('WH-DEVAN-40-LOOSE'));
        $this->assertSame(3, Charge::query()->count());
        $this->assertSame(55000, (int) Charge::query()->sum('amount_cents'));
        $charge = Charge::query()->where('job_id', $asnB->job_id)->sole();
        $this->assertSame([$b->id, "task:{$task->id}:job:{$asnB->job_id}", 1, 'container', $box->id, 'pending', '0.300', 55000, 'container_40'], [$charge->client_id, $charge->source_activity_id, $charge->activity_version, $charge->source_type, $charge->source_id, $charge->status, (string) $charge->qty, $charge->rate_snapshot_cents, $charge->uom]);
        $this->assertEquals(['basis' => 'cartons_received', 'basis_provisional' => false, 'basis_qty' => 150, 'basis_total' => 500, 'share' => 0.3, 'physical_container_no' => self::BOX, 'members_count' => 3, 'member_asn_no' => $asnB->asn_no, 'member_unpack_mode' => 'loose'], $charge->calculation_snapshot_json['allocation']); // JSON column: key order / 150.0 normalised
        $this->assertSame('standard', $charge->calculation_snapshot_json['card']);
        $this->assertDatabaseMissing('exceptions', ['type' => 'missing_rate']);

        // The box page lists the split for finance / customer service; the Job workbench of a member shows only its own charge.
        $this->actingAs($this->staff('customer_service'))->get(route('warehouse.physical_containers.show', $box))->assertOk()->assertSee('WH-DEVAN-40-LOOSE')->assertSee('165.00')->assertSee('275.00');
        $this->actingAs($this->staff('finance'))->get(route('platform.jobs.show', $asnC->job_id))->assertOk()->assertSee('110.00')->assertDontSee('275.00');
    }

    public function test_each_member_is_priced_on_its_own_card_and_a_missing_rate_hits_that_member_only(): void
    {
        $engine = app(ChargeEngine::class);
        $negotiated = $this->client();
        $standard = $this->client();
        $unpriced = $this->client(['standard_rate_card_id' => null]);
        $jobs = [];
        foreach ([$negotiated, $standard, $unpriced] as $client) {
            $jobs[$client->id] = app(JobService::class)->create($client->id, 'container')['job_id'];
        }
        $card = RateCard::query()->create(['client_id' => $negotiated->id, 'name' => 'negotiated', 'version' => 1, 'effective_from' => today()->subDay(), 'status' => 'active']);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => ChargeCode::query()->where('code', 'WH-DEVAN-40-LOOSE')->value('id'), 'pricing_mode' => 'fixed', 'rate_cents' => 50000, 'threshold_json' => ['max_line_count' => 20, 'min_billable_qty' => 1], 'min_charge_cents' => 40000]);

        $payload = $this->boxPayload(77, [[$negotiated->id, $jobs[$negotiated->id], 'loose', 0.5], [$standard->id, $jobs[$standard->id], 'loose', 0.3], [$unpriced->id, $jobs[$unpriced->id], 'loose', 0.2]]) + ['task_id' => 900, 'task_type' => 'devanning', 'billable_qty' => 1, 'billable_uom' => 'container'];
        $charges = $engine->applyEvent(['event_name' => 'task.completed', 'job_id' => null, 'client_id' => null, 'payload' => $payload]);

        // 500.00 × 0.5 on the negotiated card (its minimum quantity / minimum charge do NOT apply to a fraction), 550.00 × 0.3 on the standard card.
        $this->assertCount(2, $charges);
        $this->assertSame([$jobs[$negotiated->id] => 25000, $jobs[$standard->id] => 16500], $this->byJob('WH-DEVAN-40-LOOSE'));
        $this->assertSame(['client', 'standard'], Charge::query()->orderBy('job_id')->get()->map(fn (Charge $c) => $c->calculation_snapshot_json['card'])->all());
        $this->assertTrue(Charge::query()->where('job_id', $jobs[$negotiated->id])->sole()->calculation_snapshot_json['allocated']); // priced as a fraction: 25000 < the card's 40000 minimum, not floored
        // The unpriced member gets a Missing Rate exception on ITS Job — the others are billed; never $0.
        $this->assertSame(1, ExceptionRecord::query()->where('type', 'missing_rate')->count());
        $this->assertDatabaseHas('exceptions', ['type' => 'missing_rate', 'source_module' => 'billing', 'job_id' => $jobs[$unpriced->id], 'client_id' => $unpriced->id, 'source_type' => 'container', 'source_id' => 77]);
        $this->assertDatabaseMissing('charges', ['job_id' => $jobs[$unpriced->id]]);
    }

    public function test_each_member_pays_its_own_unpack_mode_and_the_line_cap_applies_to_the_whole_box(): void
    {
        $engine = app(ChargeEngine::class);
        $pallet = $this->client();
        $loose = $this->client();
        $mixed = $this->client();
        $jobs = [];
        foreach ([$pallet, $loose, $mixed] as $client) {
            $jobs[$client->id] = app(JobService::class)->create($client->id, 'container')['job_id'];
        }
        $members = [[$pallet->id, $jobs[$pallet->id], 'pallet', 0.5], [$loose->id, $jobs[$loose->id], 'loose', 0.3], [$mixed->id, $jobs[$mixed->id], 'mixed', 0.2]];

        // F6 (a): a palletised member pays PLT × share, a loose member LOOSE × share, a mixed member is POA — whatever the box-level mode says.
        $engine->applyEvent(['event_name' => 'task.completed', 'job_id' => null, 'client_id' => null, 'payload' => $this->boxPayload(78, $members, ['unpack_mode' => 'mixed', 'line_count' => 12]) + ['task_id' => 901, 'task_type' => 'devanning', 'billable_qty' => 1]]);
        $this->assertSame([$jobs[$pallet->id] => 14000], $this->byJob('WH-DEVAN-40-PLT'));   // 280.00 × 0.5
        $this->assertSame([$jobs[$loose->id] => 16500], $this->byJob('WH-DEVAN-40-LOOSE')); // 550.00 × 0.3
        $this->assertDatabaseHas('charges', ['job_id' => $jobs[$mixed->id], 'source_activity_id' => 'task:901:job:'.$jobs[$mixed->id], 'status' => 'needs_review', 'amount_cents' => 0]);
        $this->assertSame(3, Charge::query()->count());
        $this->assertSame(['pending', 'pending', 'needs_review'], Charge::query()->orderBy('job_id')->pluck('status')->all());

        // The 20-line cap is the WHOLE box's line count: 60 lines → every loose member is POA (needs_review), the palletised one is unaffected.
        $engine->applyEvent(['event_name' => 'task.completed', 'job_id' => null, 'client_id' => null, 'payload' => $this->boxPayload(79, [[$loose->id, $jobs[$loose->id], 'loose', 0.6], [$pallet->id, $jobs[$pallet->id], 'loose', 0.4]], ['line_count' => 60]) + ['task_id' => 902, 'task_type' => 'devanning', 'billable_qty' => 1]]);
        $sixty = Charge::query()->where('source_activity_id', 'like', 'task:902:%')->orderBy('id')->get();
        $this->assertCount(2, $sixty);
        $this->assertSame(['needs_review', 'needs_review'], $sixty->pluck('status')->all());
        $this->assertSame([0, 0], $sixty->pluck('amount_cents')->all());
        $this->assertSame(['max_line_count', 'max_line_count'], $sixty->map(fn (Charge $c) => $c->calculation_snapshot_json['reason'])->all());
        $this->assertSame([0.6, 0.4], $sixty->map(fn (Charge $c) => $c->calculation_snapshot_json['allocation']['share'])->all());
    }

    public function test_cartage_and_sideloader_are_allocated_on_the_box_arrival(): void
    {
        $engine = app(ChargeEngine::class);
        $a = $this->client();
        $b = $this->client();
        $jobA = app(JobService::class)->create($a->id, 'container')['job_id'];
        $jobB = app(JobService::class)->create($b->id, 'container')['job_id'];
        $members = [[$a->id, $jobA, 'loose', 0.6], [$b->id, $jobB, 'loose', 0.4]];

        // Our cartage, 40', 15 t, sideloader flagged: TR-CARTAGE-40 1291.60 × share per member; TR-SIDELOADER has no Edward row → Missing Rate per member (F4).
        $engine->applyEvent(['event_name' => 'physical_container.arrived', 'job_id' => null, 'client_id' => null, 'payload' => $this->boxPayload(80, $members, ['sideloader_required' => true]) + ['arrived_at' => now()->toIso8601String()]]);
        $this->assertSame([$jobA => 77496, $jobB => 51664], $this->byJob('TR-CARTAGE-40'));
        $this->assertSame(['cartage:80:job:'.$jobA, 'cartage:80:job:'.$jobB], Charge::query()->orderBy('job_id')->pluck('source_activity_id')->all());
        $this->assertSame(2, ExceptionRecord::query()->where('type', 'missing_rate')->count());
        $this->assertDatabaseHas('exceptions', ['type' => 'missing_rate', 'job_id' => $jobA, 'client_id' => $a->id, 'source_type' => 'container', 'source_id' => 80]);
        $this->assertDatabaseHas('exceptions', ['type' => 'missing_rate', 'job_id' => $jobB, 'client_id' => $b->id, 'source_type' => 'container', 'source_id' => 80]);
        $this->assertDatabaseMissing('charges', ['charge_code_id' => ChargeCode::query()->where('code', 'TR-SIDELOADER')->value('id')]);
        // Once the client's card prices the surcharge, the member is charged (150.00 × 0.6) and no new exception is raised for it.
        $card = RateCard::query()->create(['client_id' => $a->id, 'name' => 'sideloader', 'version' => 1, 'effective_from' => today()->subDay(), 'status' => 'active']);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => ChargeCode::query()->where('code', 'TR-SIDELOADER')->value('id'), 'pricing_mode' => 'fixed', 'rate_cents' => 15000]);
        $engine->applyEvent(['event_name' => 'physical_container.arrived', 'job_id' => null, 'client_id' => null, 'payload' => $this->boxPayload(81, $members, ['sideloader_required' => true])]);
        $this->assertSame([$jobA => 9000], $this->byJob('TR-SIDELOADER'));
        $this->assertSame(3, ExceptionRecord::query()->where('type', 'missing_rate')->count()); // + one for B only

        // A box the forwarder delivers (cartage_by_us = false) bills no cartage; a 23 t box is POA for every member (22.5 t cap on the whole box).
        $engine->applyEvent(['event_name' => 'physical_container.arrived', 'job_id' => null, 'client_id' => null, 'payload' => $this->boxPayload(82, $members, ['cartage_by_us' => false])]);
        $this->assertSame(0, Charge::query()->where('source_activity_id', 'like', 'cartage:82:%')->count());
        $engine->applyEvent(['event_name' => 'physical_container.arrived', 'job_id' => null, 'client_id' => null, 'payload' => $this->boxPayload(83, $members, ['gross_weight_kg' => 23000.0])]);
        $heavy = Charge::query()->where('source_activity_id', 'like', 'cartage:83:%')->orderBy('id')->get();
        $this->assertSame(['needs_review', 'needs_review'], $heavy->pluck('status')->all());
        $this->assertSame(['max_gross_weight_kg', 'max_gross_weight_kg'], $heavy->map(fn (Charge $c) => $c->calculation_snapshot_json['reason'])->all());

        // End to end through Warehouse: 登记到港 publishes the event, the dispatcher bills it once.
        $warehouse = $this->warehouse();
        $asnA = $this->memberAsn($a, $warehouse, 300);
        $asnB = $this->memberAsn($b, $warehouse, 100);
        $boxes = app(PhysicalContainerService::class);
        $box = $boxes->create(['container_no' => self::BOX, 'warehouse_id' => $warehouse->id, 'size' => '20', 'unpack_mode' => 'loose', 'gross_weight_kg' => 9000, 'cartage_by_us' => true]);
        $boxes->link($box, Container::query()->where('container_no', self::BOX)->pluck('id')->all());
        $this->actingAs($this->staff('customer_service'))->post(route('warehouse.physical_containers.arrive', $box))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('arrived', $box->fresh()->status);
        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame([$asnA->job_id => 92295, $asnB->job_id => 30765], $this->byJob('TR-CARTAGE-20')); // 1230.60 × 0.75 / 0.25
        $this->assertSame(2, Charge::query()->where('source_activity_id', 'like', "cartage:{$box->id}:%")->count());
        $this->actingAs($this->staff('customer_service'))->post(route('warehouse.physical_containers.arrive', $box))->assertSessionHasErrors('arrive'); // once
    }

    public function test_a_higher_allocation_version_reverses_the_whole_previous_split_including_a_member_that_left(): void
    {
        $engine = app(ChargeEngine::class);
        $a = $this->client();
        $b = $this->client();
        $c = $this->client();
        $jobA = app(JobService::class)->create($a->id, 'container')['job_id'];
        $jobB = app(JobService::class)->create($b->id, 'container')['job_id'];
        $jobC = app(JobService::class)->create($c->id, 'container')['job_id'];
        $task = fn (array $members, int $version) => ['event_name' => 'task.completed', 'job_id' => null, 'client_id' => null, 'payload' => $this->boxPayload(90, $members, ['activity_version' => $version]) + ['task_id' => 910, 'task_type' => 'devanning', 'billable_qty' => 1]];

        $engine->applyEvent($task([[$a->id, $jobA, 'loose', 0.5], [$b->id, $jobB, 'loose', 0.5]], 1));
        $engine->applyEvent($task([[$a->id, $jobA, 'loose', 0.5], [$b->id, $jobB, 'loose', 0.5]], 1)); // replay of version 1: nothing new
        $this->assertSame([$jobA => 27500, $jobB => 27500], $this->byJob('WH-DEVAN-40-LOOSE'));
        $this->assertSame(2, Charge::query()->count());

        // 重算分摊: B left, C joined, A grew — version 2 reverses BOTH version-1 charges (B's key is not re-emitted) and creates A / C anew.
        $engine->applyEvent($task([[$a->id, $jobA, 'loose', 0.7], [$c->id, $jobC, 'loose', 0.3]], 2));
        $this->assertSame([$jobA => 38500, $jobC => 16500], $this->byJob('WH-DEVAN-40-LOOSE'));
        $this->assertSame(2, Charge::query()->where('activity_version', 1)->whereNull('reversal_of_charge_id')->where('status', 'reversed')->count());
        $this->assertSame(2, Charge::query()->where('activity_version', 1)->whereNotNull('reversal_of_charge_id')->count()); // audit twins, −27500 each
        $this->assertSame(-27500, Charge::query()->where('job_id', $jobB)->whereNotNull('reversal_of_charge_id')->value('amount_cents'));
        $this->assertSame(0, (int) Charge::query()->where('job_id', $jobB)->sum('amount_cents')); // B nets to nothing
        $this->assertSame(55000, (int) Charge::query()->where('status', '!=', 'reversed')->sum('amount_cents')); // the box is still charged exactly once
        $engine->applyEvent($task([[$a->id, $jobA, 'loose', 0.7], [$c->id, $jobC, 'loose', 0.3]], 2)); // replay of version 2
        $this->assertSame(6, Charge::query()->count());

        // The single-client path is untouched: a task.completed WITHOUT members bills billable_qty once under task:{id} on the envelope Job.
        $engine->applyEvent(['event_name' => 'task.completed', 'job_id' => $jobA, 'client_id' => $a->id, 'payload' => ['task_id' => 911, 'task_type' => 'devanning', 'billable_qty' => 1, 'container' => ['size' => '40', 'unpack_mode' => 'loose', 'line_count' => 5]]]);
        $this->assertDatabaseHas('charges', ['source_activity_id' => 'task:911', 'job_id' => $jobA, 'amount_cents' => 55000, 'source_type' => 'task', 'source_id' => 911, 'qty' => 1]);
    }
}
