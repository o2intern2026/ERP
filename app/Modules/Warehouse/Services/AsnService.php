<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnLine;
use App\Support\Contracts\JobService;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** B2: ASN creation (planned or unplanned), containers (basic fields), goods lines; B2b import writes lines through addLines(). */
final class AsnService
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
}
