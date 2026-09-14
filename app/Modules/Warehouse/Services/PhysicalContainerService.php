<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Events\PhysicalContainerArrived;
use App\Modules\Warehouse\Events\TaskCompleted;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\Container;
use App\Modules\Warehouse\Models\PhysicalContainer;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 物理柜 / 拼柜 (CHANGE_REQUESTS #122, lead 2026-09-14 — all seven defaults). One physical box, N container rows of N ASNs /
 * clients. The coordinator links the rows here; the box carries the ONE devanning task and the allocation basis; Warehouse
 * computes every member's share (Billing never guesses, ERP_PLAN §4.3) and publishes it inside `task.completed` (devanning)
 * and `physical_container.arrived` (cartage / sideloader). Shares are stored on the member rows, sum to 1.0000, and can be
 * re-emitted with activity_version + 1 (重算分摊) — the charge engine reverses the older version.
 *
 * Basis (default cartons_received; pallets when the box is palletised; equal when the basis total is 0): while any member
 * ASN is still receiving, the split is provisional on the pre-advised cartons and 重算分摊 finalises it on the actual count.
 * Unlinked container rows are never touched — the single-client (FCL) path bills exactly as before.
 */
final class PhysicalContainerService
{
    public function __construct(private readonly OutboxPublisher $outbox, private readonly TaskService $tasks) {}

    /**
     * @param  array{container_no:string, warehouse_id:int, size:string, unpack_mode:string, gross_weight_kg?:?float, allocation_basis?:?string, cartage_by_us?:bool, sideloader_required?:bool, eta_date?:?string, notes?:?string, created_by?:?int}  $data
     */
    public function create(array $data): PhysicalContainer
    {
        $no = strtoupper(trim($data['container_no']));
        $eta = filled($data['eta_date'] ?? null) ? $data['eta_date'] : null;
        $basis = $data['allocation_basis'] ?? null;
        if ($basis !== null && ! in_array($basis, Enums::ALLOCATION_BASES, true)) {
            throw new RuleViolation("Unknown allocation basis {$basis}.", 'warehouse.physical_containers.errors.invalid_basis');
        }

        return DB::transaction(function () use ($data, $no, $eta, $basis): PhysicalContainer {
            $open = PhysicalContainer::query()->where('container_no', $no)->where('warehouse_id', $data['warehouse_id'])->whereIn('status', ['expected', 'arrived'])->first();
            if ($open !== null) {
                throw new RuleViolation("Physical container {$no} is already registered and not yet devanned.", 'warehouse.physical_containers.errors.already_open', ['no' => $no]);
            }
            if (PhysicalContainer::query()->where('container_no', $no)->where('eta_date', $eta)->exists()) {
                throw new RuleViolation("Physical container {$no} with ETA {$eta} already exists.", 'warehouse.physical_containers.errors.duplicate_voyage', ['no' => $no, 'eta' => $eta ?? '—']);
            }

            return PhysicalContainer::query()->create([
                'container_no' => $no,
                'warehouse_id' => (int) $data['warehouse_id'],
                'size' => $data['size'],
                'unpack_mode' => $data['unpack_mode'],
                'gross_weight_kg' => filled($data['gross_weight_kg'] ?? null) ? (float) $data['gross_weight_kg'] : null,
                'consolidation' => 'fcl',
                'allocation_basis' => $basis ?? ($data['unpack_mode'] === 'pallet' ? 'pallets' : 'cartons_received'), // F1 default
                'cartage_by_us' => (bool) ($data['cartage_by_us'] ?? false),
                'sideloader_required' => (bool) ($data['sideloader_required'] ?? false),
                'eta_date' => $eta,
                'status' => 'expected',
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);
        });
    }

    /**
     * 关联: container rows (any client, same warehouse) join the box. Refuses a row already in another box or an ASN of another
     * warehouse; a size that differs from the box is flagged, never overwritten (the ASN keeps what the client declared).
     *
     * @param  list<int>  $containerIds
     * @return array{linked:int, warnings:list<string>}
     */
    public function link(PhysicalContainer $box, array $containerIds): array
    {
        return DB::transaction(function () use ($box, $containerIds): array {
            $box = PhysicalContainer::query()->lockForUpdate()->findOrFail($box->id);
            $rows = Container::query()->with(['asn' => fn ($q) => $q->withoutGlobalScopes()])->whereIn('id', array_values(array_unique(array_map('intval', $containerIds))))->orderBy('id')->get();
            $linked = 0;
            $warnings = [];

            foreach ($rows as $row) {
                if ($row->physical_container_id === $box->id) {
                    continue;
                }
                if ($row->physical_container_id !== null) {
                    throw new RuleViolation("Container row {$row->container_no} (ASN {$row->asn->asn_no}) is already linked to another physical container.", 'warehouse.physical_containers.errors.already_linked', ['no' => $row->container_no, 'asn' => $row->asn->asn_no]);
                }
                if ((int) $row->asn->warehouse_id !== (int) $box->warehouse_id) {
                    throw new RuleViolation("ASN {$row->asn->asn_no} belongs to another warehouse than physical container {$box->container_no}.", 'warehouse.physical_containers.errors.warehouse_mismatch', ['no' => $box->container_no, 'asn' => $row->asn->asn_no]);
                }
                if ($row->size !== $box->size) {
                    $warnings[] = __('warehouse.physical_containers.warnings.size_mismatch', ['asn' => $row->asn->asn_no, 'member' => __('warehouse.container_sizes.'.$row->size), 'box' => __('warehouse.container_sizes.'.$box->size)]);
                }
                if ($row->unpack_mode !== $box->unpack_mode) {
                    $warnings[] = __('warehouse.physical_containers.warnings.mode_mismatch', ['asn' => $row->asn->asn_no, 'member' => __('warehouse.unpack_modes.'.$row->unpack_mode), 'box' => __('warehouse.unpack_modes.'.$box->unpack_mode)]);
                }
                $row->update(['physical_container_id' => $box->id]);
                $linked++;
            }

            if ($linked > 0) {
                $this->refreshConsolidation($box, true);
            }

            return ['linked' => $linked, 'warnings' => $warnings];
        });
    }

    /** 解除关联: refused once the box has been devanned — the member's share has been billed; Finance reverses, the box is re-split. */
    public function unlink(PhysicalContainer $box, Container $container): void
    {
        DB::transaction(function () use ($box, $container): void {
            $box = PhysicalContainer::query()->lockForUpdate()->findOrFail($box->id);
            if ((int) $container->physical_container_id !== (int) $box->id) {
                throw new RuleViolation("Container row {$container->container_no} is not a member of physical container {$box->container_no}.", 'warehouse.physical_containers.errors.not_member', ['no' => $container->container_no]);
            }
            if ($box->isDevanned()) {
                throw new RuleViolation("Physical container {$box->container_no} has been devanned; members cannot be unlinked any more.", 'warehouse.physical_containers.errors.cannot_unlink_billed', ['no' => $box->container_no]);
            }
            $container->update(['physical_container_id' => null, 'devanning_basis' => null, 'devanning_basis_qty' => null, 'devanning_share' => null, 'devanning_basis_provisional' => false]);
            $this->refreshConsolidation($box, true);
        });
    }

    /**
     * The split of one box over its members, computed from Warehouse's own quantities — never persisted here (see snapshotShares).
     *
     * @return array{basis_requested:string, basis:string, provisional:bool, basis_total:float, receiving_done:bool, members:list<array<string, mixed>>, totals:array<string, int|float>}
     */
    public function shares(PhysicalContainer $box, ?string $basis = null): array
    {
        $basis ??= $box->allocation_basis;
        if (! in_array($basis, Enums::ALLOCATION_BASES, true)) {
            throw new RuleViolation("Unknown allocation basis {$basis}.", 'warehouse.physical_containers.errors.invalid_basis');
        }
        $members = $box->members()->with(['asn' => fn ($q) => $q->withoutGlobalScopes()])->get();
        if ($members->isEmpty()) {
            throw new RuleViolation("Physical container {$box->container_no} has no member container rows.", 'warehouse.physical_containers.errors.no_members', ['no' => $box->container_no]);
        }

        $lines = AsnLine::query()->whereIn('container_id', $members->modelKeys())->get(['id', 'container_id', 'expected_cartons', 'received_cartons', 'damaged_cartons', 'cbm'])->groupBy('container_id');
        $palletsByLine = StockUnit::query()->withoutGlobalScopes()->whereIn('asn_line_id', $lines->flatten()->pluck('id'))->where('unit_type', 'pallet')
            ->selectRaw('asn_line_id, count(*) as n')->groupBy('asn_line_id')->pluck('n', 'asn_line_id');

        $rows = [];
        foreach ($members as $member) {
            $memberLines = $lines->get($member->id, collect());
            $rows[] = [
                'container_id' => $member->id,
                'container_no' => $member->container_no,
                'asn_id' => $member->asn_id,
                'asn_no' => $member->asn->asn_no,
                'job_id' => (int) $member->job_id,
                'client_id' => (int) $member->asn->client_id,
                'size' => $member->size,
                'unpack_mode' => $member->unpack_mode,
                'line_count' => (int) $member->line_count,
                'cartons_expected' => (int) $memberLines->sum('expected_cartons'),
                'cartons_received' => (int) $memberLines->sum('received_cartons'),
                'cbm' => round((float) $memberLines->sum(fn ($l) => (float) ($l->cbm ?? 0)), 4),
                'pallets' => (int) $memberLines->sum(fn ($l) => (int) ($palletsByLine[$l->id] ?? 0)),
                'receiving_done' => $member->asn->receiving_completed_at !== null || in_array($member->asn->status, ['putaway', 'closed'], true),
            ];
        }

        $receivingDone = array_reduce($rows, fn (bool $carry, array $r) => $carry && $r['receiving_done'], true);
        [$effective, $provisional, $qtyKey] = $this->resolveBasis($basis, $rows, $receivingDone);
        $quantities = array_map(fn (array $r): float => $qtyKey === null ? 1.0 : (float) $r[$qtyKey], $rows);
        $total = array_sum($quantities);

        $count = count($rows);
        $running = 0.0;
        foreach ($rows as $i => $row) {
            $share = $i === $count - 1 ? max(0.0, round(1.0 - $running, 4)) : round($quantities[$i] / $total, 4); // the last member absorbs the rounding so Σ = 1.0000, never below 0
            $running += $share;
            $rows[$i]['basis_qty'] = $quantities[$i];
            $rows[$i]['share'] = $share;
        }

        return [
            'basis_requested' => $basis,
            'basis' => $effective,
            'provisional' => $provisional,
            'basis_total' => (float) $total,
            'receiving_done' => $receivingDone,
            'members' => $rows,
            'totals' => [
                'members_count' => $count,
                'clients_count' => count(array_unique(array_column($rows, 'client_id'))),
                'line_count' => (int) array_sum(array_column($rows, 'line_count')),
                'cartons_expected' => (int) array_sum(array_column($rows, 'cartons_expected')),
                'cartons_received' => (int) array_sum(array_column($rows, 'cartons_received')),
                'cbm' => round((float) array_sum(array_column($rows, 'cbm')), 4),
                'pallets' => (int) array_sum(array_column($rows, 'pallets')),
            ],
        ];
    }

    /**
     * 登记到港: the box was delivered — publish `physical_container.arrived` with the members' shares so Billing can allocate
     * cartage (cartage_by_us) and the sideloader surcharge. Once per box; 重算分摊 re-emits.
     */
    public function markArrived(PhysicalContainer $box, ?int $userId = null): PhysicalContainer
    {
        return DB::transaction(function () use ($box, $userId): PhysicalContainer {
            $box = PhysicalContainer::query()->lockForUpdate()->findOrFail($box->id);
            if ($box->arrived_at !== null) {
                throw new RuleViolation("Physical container {$box->container_no} is already marked arrived.", 'warehouse.physical_containers.errors.already_arrived', ['no' => $box->container_no]);
            }
            $version = max(1, $box->allocation_version);
            $shares = $this->snapshotShares($box);
            $event = new PhysicalContainerArrived($this->boxPayload($box, $shares, $version) + ['arrived_at' => now()->toIso8601String(), 'arrived_by' => $userId ?? auth()->id()], jobId: null, clientId: null, correlationId: $box->container_no);
            $box->update([
                'status' => $box->isDevanned() ? 'devanned' : 'arrived',
                'arrived_at' => now(),
                'arrived_event_id' => $event->eventId,
                'allocation_version' => $version,
                'allocation_stale' => false,
            ]);
            $this->outbox->publish($event);

            return $box;
        });
    }

    /** 登记拆柜: the ONE box-level devanning task (job / client NULL; completed from the task list like any VAS task). */
    public function registerDevanning(PhysicalContainer $box, ?string $notes = null): WarehouseTask
    {
        return DB::transaction(function () use ($box, $notes): WarehouseTask {
            $box = PhysicalContainer::query()->lockForUpdate()->findOrFail($box->id);
            $existing = WarehouseTask::query()->withoutGlobalScopes()->where('physical_container_id', $box->id)->where('task_type', 'devanning')->where('status', '!=', 'cancelled')->first();
            if ($existing !== null) {
                throw new RuleViolation("Physical container {$box->container_no} already has devanning task {$existing->task_no}.", 'warehouse.physical_containers.errors.box_has_task', ['no' => $box->container_no, 'task_no' => $existing->task_no]);
            }
            if (! $box->members()->exists()) {
                throw new RuleViolation("Physical container {$box->container_no} has no member container rows.", 'warehouse.physical_containers.errors.no_members', ['no' => $box->container_no]);
            }
            $task = $this->tasks->create('devanning', [
                'job_id' => null, 'client_id' => null, 'warehouse_id' => $box->warehouse_id,
                'source_type' => 'physical_container', 'source_id' => $box->id, 'physical_container_id' => $box->id, 'notes' => $notes,
            ]);
            $box->update(['devanning_task_id' => $task->id]);

            return $task;
        });
    }

    /**
     * Called by TaskService::complete inside its transaction for a box-level devanning task: snapshot the shares, mark the box
     * devanned and return the event payload (task fields + box + members). Version = the box's current allocation version.
     *
     * @return array{payload:array<string, mixed>, version:int}
     */
    public function completeDevanning(PhysicalContainer $box, WarehouseTask $task, ?int $scanCount = null): array
    {
        $box = PhysicalContainer::query()->lockForUpdate()->findOrFail($box->id);
        $version = max(1, $box->allocation_version);
        $shares = $this->snapshotShares($box);
        $box->update(['status' => 'devanned', 'devanned_at' => now(), 'devanning_task_id' => $task->id, 'allocation_version' => $version, 'allocation_stale' => false]);

        return ['payload' => array_replace(TaskService::eventPayload($task, $scanCount), $this->boxPayload($box, $shares, $version)), 'version' => $version];
    }

    /**
     * 重算分摊: re-split on today's quantities / members and re-emit every event already published for this box with
     * activity_version + 1 — the engine reverses the older charges (cancel / redo = reversal rows, never deletion).
     *
     * @return array<string, mixed> the new shares
     */
    public function recompute(PhysicalContainer $box, ?int $userId = null): array
    {
        return DB::transaction(function () use ($box, $userId): array {
            $box = PhysicalContainer::query()->lockForUpdate()->findOrFail($box->id);
            if (! $box->hasEmitted()) {
                throw new RuleViolation("Physical container {$box->container_no} has not been billed yet; nothing to recompute.", 'warehouse.physical_containers.errors.nothing_to_recompute', ['no' => $box->container_no]);
            }
            $version = $box->allocation_version + 1;
            $shares = $this->snapshotShares($box);
            $box->update(['allocation_version' => $version, 'allocation_stale' => false]);

            if ($box->arrived_event_id !== null) {
                $this->outbox->publish(new PhysicalContainerArrived($this->boxPayload($box, $shares, $version) + ['arrived_at' => $box->arrived_at?->toIso8601String(), 'arrived_by' => $userId ?? auth()->id(), 'recomputed_by' => $userId ?? auth()->id()], jobId: null, clientId: null, correlationId: $box->container_no));
            }
            $task = $box->devanning_task_id ? WarehouseTask::query()->withoutGlobalScopes()->find($box->devanning_task_id) : null;
            if ($box->isDevanned() && $task !== null && $task->status === 'done') {
                $this->outbox->publish(new TaskCompleted(array_replace(TaskService::eventPayload($task), $this->boxPayload($box, $shares, $version), ['recomputed_by' => $userId ?? auth()->id()]), jobId: null, clientId: null, correlationId: $box->container_no));
            }

            return $shares;
        });
    }

    /** Unlinked container rows the picker offers: same warehouse; the box's number first, then a free search by ASN / container number. */
    public function candidates(PhysicalContainer $box, ?string $search = null, int $limit = 50): Collection
    {
        $q = Container::query()->with(['asn' => fn ($q) => $q->withoutGlobalScopes()->with('client')])->whereNull('physical_container_id')
            ->whereHas('asn', fn ($a) => $a->withoutGlobalScopes()->where('warehouse_id', $box->warehouse_id));
        $search = trim((string) $search);
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('container_no', 'like', "%{$search}%")->orWhereHas('asn', fn ($a) => $a->withoutGlobalScopes()->where('asn_no', 'like', "%{$search}%")->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"))));
        } else {
            $q->where('container_no', $box->container_no);
        }

        return $q->orderByDesc('id')->limit($limit)->get();
    }

    /** Compute the shares and store them on the member rows (auditable, shown on the box page). */
    private function snapshotShares(PhysicalContainer $box): array
    {
        $shares = $this->shares($box);
        foreach ($shares['members'] as $m) {
            Container::query()->whereKey($m['container_id'])->update([
                'devanning_basis' => $shares['basis'], 'devanning_basis_qty' => $m['basis_qty'], 'devanning_share' => $m['share'], 'devanning_basis_provisional' => $shares['provisional'],
            ]);
        }

        return $shares;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0:string, 1:bool, 2:?string} effective basis, provisional, the row key that carries the quantity (null = equal)
     */
    private function resolveBasis(string $basis, array $rows, bool $receivingDone): array
    {
        $sum = fn (string $key): float => (float) array_sum(array_column($rows, $key));

        [$key, $provisional] = match ($basis) {
            'cartons_received' => $receivingDone ? ['cartons_received', false] : ['cartons_expected', true], // pre-advised cartons until every member is counted
            'pallets' => ['pallets', ! $receivingDone],
            'cbm' => ['cbm', false],
            'lines' => ['line_count', false],
            default => [null, false],
        };
        if ($key === 'cartons_expected' && $sum($key) <= 0 && $sum('cartons_received') > 0) {
            $key = 'cartons_received';
        }
        if ($key !== null && $sum($key) <= 0) {
            return ['equal', $provisional || $basis !== 'equal', null];
        }

        return [$key === null ? 'equal' : $basis, $provisional, $key];
    }

    /** Derived, never edited: fcl while one client owns every member, lcl from the second client on; members changing after an emission flag 重算分摊. */
    private function refreshConsolidation(PhysicalContainer $box, bool $membersChanged): void
    {
        $clients = Container::query()->where('physical_container_id', $box->id)
            ->join('asns', 'asns.id', '=', 'containers.asn_id')->distinct()->pluck('asns.client_id');
        $box->update([
            'consolidation' => $clients->count() > 1 ? 'lcl' : 'fcl',
            'allocation_stale' => $box->allocation_stale || ($membersChanged && $box->hasEmitted()),
        ]);
    }

    /**
     * The box part of both events (contracts/events.md): flat ids the rule templates can render, the box facts, the alias
     * `container` so the existing devanning conditions match, and members[] with each share.
     *
     * @return array<string, mixed>
     */
    private function boxPayload(PhysicalContainer $box, array $shares, int $version): array
    {
        $t = $shares['totals'];

        return [
            'physical_container_id' => $box->id,
            'physical_container' => [
                'id' => $box->id,
                'container_no' => $box->container_no,
                'warehouse_id' => $box->warehouse_id,
                'size' => $box->size,
                'unpack_mode' => $box->unpack_mode,
                'gross_weight_kg' => $box->gross_weight_kg !== null ? (float) $box->gross_weight_kg : null,
                'consolidation' => $box->consolidation,
                'sideloader_required' => (bool) $box->sideloader_required,
                'cartage_by_us' => (bool) $box->cartage_by_us,
                'line_count_total' => $t['line_count'],
                'cartons_expected_total' => $t['cartons_expected'],
                'cartons_received_total' => $t['cartons_received'],
                'cbm_total' => $t['cbm'],
                'pallets_total' => $t['pallets'],
                'members_count' => $t['members_count'],
            ],
            'container' => ['size' => $box->size, 'unpack_mode' => $box->unpack_mode, 'line_count' => $t['line_count'], 'gross_weight_kg' => $box->gross_weight_kg !== null ? (float) $box->gross_weight_kg : null],
            'members' => array_map(fn (array $m): array => [
                'asn_id' => $m['asn_id'], 'asn_no' => $m['asn_no'], 'container_id' => $m['container_id'], 'container_no' => $m['container_no'],
                'job_id' => $m['job_id'], 'client_id' => $m['client_id'], 'unpack_mode' => $m['unpack_mode'], 'line_count' => $m['line_count'],
                'cartons_expected' => $m['cartons_expected'], 'cartons_received' => $m['cartons_received'], 'cbm' => $m['cbm'], 'pallets' => $m['pallets'],
                'basis_qty' => $m['basis_qty'], 'share' => $m['share'],
            ], $shares['members']),
            'allocation_basis' => $shares['basis'],
            'allocation_basis_requested' => $shares['basis_requested'],
            'basis_provisional' => $shares['provisional'],
            'basis_total' => $shares['basis_total'],
            'cartage_by_us' => (bool) $box->cartage_by_us,
            'sideloader_required' => (bool) $box->sideloader_required,
            'activity_version' => $version,
        ];
    }
}
