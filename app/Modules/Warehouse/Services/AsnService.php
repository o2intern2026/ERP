<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnLine;
use App\Support\Contracts\InboundService;
use App\Support\Contracts\JobService;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** B2: ASN creation (planned or unplanned), containers (basic fields), goods lines; B2b import writes lines through addLines(). */
final class AsnService implements InboundService
{
    public function __construct(private readonly JobService $jobs) {}

    /**
     * @param  array{client_id:int, warehouse_id:int, inbound_type:string, expected_date?:?string, job_id?:?int, job_type?:string, reference?:?string, created_by_type?:string, unplanned?:bool, notes?:?string, containers?:list<array{container_no:string, size:string, unpack_mode:string, gross_weight_kg?:?float}>}  $data
     */
    public function create(array $data): Asn
    {
        return DB::transaction(function () use ($data): Asn {
            $jobId = $data['job_id'] ?? null;
            if ($jobId === null) {
                $jobType = $data['job_type'] ?? ($data['inbound_type'] === 'container' ? 'container' : 'loose');
                $jobId = $this->jobs->create((int) $data['client_id'], $jobType, ['reference' => $data['reference'] ?? null])['job_id'];
            }

            $asn = Asn::query()->create([
                'asn_no' => DocumentNumbers::next(Asn::query()->withoutGlobalScopes(), 'asn_no', 'ASN'),
                'job_id' => $jobId,
                'client_id' => $data['client_id'],
                'warehouse_id' => $data['warehouse_id'],
                'expected_date' => $data['expected_date'] ?? null,
                'inbound_type' => $data['inbound_type'],
                'status' => 'booked',
                'created_by_type' => $data['created_by_type'] ?? 'coordinator',
                'created_by' => auth()->id(),
                'unplanned' => (bool) ($data['unplanned'] ?? false),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['containers'] ?? [] as $container) {
                $asn->containers()->create($container + ['job_id' => $jobId]);
            }

            return $asn;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines  asn_lines columns; `container_no` may be given instead of container_id
     * @return list<AsnLine>
     */
    public function addLines(Asn $asn, array $lines): array
    {
        return DB::transaction(function () use ($asn, $lines): array {
            $containers = $asn->containers()->get()->keyBy('container_no');
            $created = [];

            foreach ($lines as $line) {
                if (isset($line['container_no'])) {
                    $line['container_id'] = $containers->get($line['container_no'])?->id;
                    unset($line['container_no']);
                }
                $created[] = $asn->lines()->create($line);
            }

            foreach ($asn->containers as $container) {
                $container->update(['line_count' => $container->lines()->count()]);
            }

            return $created;
        });
    }

    public function markArrived(Asn $asn): void
    {
        $asn->update(['status' => 'arrived', 'arrived_at' => now()]);
    }

    /** Unplanned arrivals must be confirmed by a coordinator before putaway (ERP_PLAN §4.2 asns.unplanned). */
    public function confirmUnplanned(Asn $asn): void
    {
        $asn->update(['unplanned_confirmed' => true]);
    }

    /**
     * 编辑收件信息 (CHANGE_REQUESTS #115): the consignee fields and mark of one goods line, optionally copied to the ASN's other
     * lines under the same mark — one mark becomes one 派送订单, so OMS needs them to agree. Refused once the line sits on an
     * order (order_line_id): from then on the order carries the address and is edited there.
     *
     * @param  array<string, mixed>  $data  consignment_mark, deliver_to_*, fba_reference (other keys ignored)
     * @return int lines updated
     */
    public function updateLineDelivery(AsnLine $line, array $data, bool $applyToMark = false): int
    {
        if ($line->order_line_id !== null) {
            throw new RuleViolation("ASN line {$line->id} is already on an order; edit the address on the order.", 'warehouse.asns.errors.delivery_locked', ['id' => $line->id]);
        }
        $fields = Arr::only($data, ['consignment_mark', 'deliver_to_name', 'deliver_to_phone', 'deliver_to_address', 'deliver_to_suburb', 'deliver_to_state', 'deliver_to_postcode', 'fba_reference']);

        return DB::transaction(function () use ($line, $fields, $applyToMark): int {
            $line->update($fields);
            $mark = trim((string) $line->consignment_mark);
            if (! $applyToMark || $mark === '') {
                return 1;
            }

            return 1 + AsnLine::query()->where('asn_id', $line->asn_id)->whereKeyNot($line->id)->where('consignment_mark', $line->consignment_mark)
                ->whereNull('order_line_id')->update(Arr::except($fields, ['consignment_mark']));
        });
    }

    /**
     * 确认客户预报 (CHANGE_REQUESTS #116): customer service has checked a client-submitted ASN (container, ETA, goods lines).
     * A flag for the screens and the portal only — receiving and putaway never wait for it.
     */
    public function confirmClientSubmission(Asn $asn, ?int $userId): void
    {
        if (! $asn->isClientSubmitted()) {
            throw new RuleViolation("ASN {$asn->asn_no} was not submitted by the client.", 'warehouse.asns.errors.not_client_submitted', ['no' => $asn->asn_no]);
        }
        if ($asn->client_confirmed_at !== null) {
            throw new RuleViolation("ASN {$asn->asn_no} is already confirmed.", 'warehouse.asns.errors.already_confirmed', ['no' => $asn->asn_no]);
        }
        $asn->update(['client_confirmed_at' => now(), 'client_confirmed_by' => $userId]);
    }

    /**
     * The client re-uploads its packing list while the submission is still a draft: drop the lines nobody has touched so the new
     * list replaces them instead of doubling up. Only for a booked, unconfirmed client submission whose lines have no receipt,
     * stock unit or order behind them — otherwise the list is history and staff amend it by hand.
     *
     * @return int lines removed
     */
    public function clearDraftLines(Asn $asn): int
    {
        if (! $asn->isPendingClientConfirmation() || $asn->status !== 'booked') {
            throw new RuleViolation("ASN {$asn->asn_no} is no longer a draft submission.", 'warehouse.asns.errors.draft_lines_locked', ['no' => $asn->asn_no]);
        }

        return DB::transaction(function () use ($asn): int {
            $lines = $asn->lines()->with(['stockUnits', 'receiptLine'])->lockForUpdate()->get();
            if ($lines->contains(fn (AsnLine $l) => $l->isReceived() || $l->isOnOrder())) {
                throw new RuleViolation("ASN {$asn->asn_no} has lines that were already received or ordered.", 'warehouse.asns.errors.draft_lines_locked', ['no' => $asn->asn_no]);
            }
            $removed = $lines->isEmpty() ? 0 : $asn->lines()->whereKey($lines->modelKeys())->delete();
            foreach ($asn->containers()->get() as $container) {
                $container->update(['line_count' => 0]);
            }

            return $removed;
        });
    }

    /** Client submissions waiting for customer service — the 预报单 nav badge (all warehouses). */
    public function pendingClientConfirmationCount(): int
    {
        return Asn::query()->where('created_by_type', 'client')->whereNull('client_confirmed_at')->count();
    }

    /**
     * InboundService (CHANGE_REQUESTS #117): 从订单生成预报单 — Orders hands over the goods lines of the client's orders; the ASN is
     * created here exactly like a coordinator's (created_by_type coordinator, under the Job Orders chose) and every line keeps its
     * order_line_id, so the two documents are linked in both directions and 从预报单生成派送订单 skips these lines.
     */
    public function createAsnFromOrderLines(array $header, array $lines): array
    {
        if ($lines === []) {
            throw new RuleViolation('No goods lines to put on the ASN.', 'warehouse.asns.errors.no_lines_for_asn');
        }

        return DB::transaction(function () use ($header, $lines): array {
            $asn = $this->create(Arr::only($header, ['client_id', 'warehouse_id', 'job_id', 'inbound_type', 'expected_date', 'notes', 'reference', 'containers']));

            return $this->appendOrderLines($asn, $lines);
        });
    }

    /**
     * InboundService (CHANGE_REQUESTS #119): 从订单导入货物行 — the same hand-over onto an ASN that already exists. Only while the
     * ASN is still booked / arrived / receiving: once putaway starts the goods lines are history and the orders are matched by
     * 从预报单生成派送订单 instead. A line without a container goes onto the ASN's only container when it has exactly one; on a
     * multi-container ASN every line must name one of them (the devanning band is billed per container line_count).
     */
    public function addOrderLinesToAsn(int $asnId, array $lines): array
    {
        if ($lines === []) {
            throw new RuleViolation('No goods lines to put on the ASN.', 'warehouse.asns.errors.no_lines_for_asn');
        }

        return DB::transaction(function () use ($asnId, $lines): array {
            // The ASN row is held for the whole append: PutawayService::completeIfDone takes the same lock before it flips the
            // status, so the check below and the insert cannot straddle a putaway completion.
            $asn = Asn::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($asnId);
            if (! in_array($asn->status, ['booked', 'arrived', 'receiving'], true)) {
                throw new RuleViolation("ASN {$asn->asn_no} is past receiving; order lines cannot be imported.", 'warehouse.asns.errors.import_orders_closed', ['no' => $asn->asn_no]);
            }

            $containers = $asn->containers()->orderBy('id')->pluck('container_no')->map(fn ($no) => (string) $no);
            if ($containers->count() === 1) {
                $only = $containers->first();
                $lines = array_map(fn (array $line): array => filled($line['container_no'] ?? null) ? $line : [...$line, 'container_no' => $only], $lines);
            }
            foreach ($lines as $line) {
                $no = $line['container_no'] ?? null;
                if (($containers->count() > 1 && ! filled($no)) || (filled($no) && ! $containers->contains((string) $no))) {
                    throw new RuleViolation("ASN {$asn->asn_no}: every imported line must sit on one of its containers.", 'warehouse.asns.errors.import_orders_pick_container', ['no' => $asn->asn_no, 'containers' => $containers->isEmpty() ? '—' : $containers->implode(', ')]);
                }
            }

            return $this->appendOrderLines($asn, $lines);
        });
    }

    /**
     * Shared tail of createAsnFromOrderLines / addOrderLinesToAsn: whitelist the asn_lines columns, write the lines, report the
     * order_line → asn_line mapping the caller needs for its side of the link.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{asn_id:int, asn_no:string, lines:list<array{order_line_id:int, asn_line_id:int}>}
     */
    private function appendOrderLines(Asn $asn, array $lines): array
    {
        $created = $this->addLines($asn, array_map(fn (array $line): array => Arr::only($line, [
            'order_line_id', 'container_no', 'consignment_mark', 'description', 'expected_cartons', 'package_type', 'deliver_to_name', 'deliver_to_phone',
            'deliver_to_address', 'deliver_to_suburb', 'deliver_to_state', 'deliver_to_postcode', 'fba_reference', 'weight_kg', 'length_mm', 'width_mm', 'height_mm', 'cbm',
        ]), $lines));

        return [
            'asn_id' => $asn->id,
            'asn_no' => $asn->asn_no,
            'lines' => array_values(array_map(fn (AsnLine $l): array => ['order_line_id' => (int) $l->order_line_id, 'asn_line_id' => $l->id], $created)),
        ];
    }
}
