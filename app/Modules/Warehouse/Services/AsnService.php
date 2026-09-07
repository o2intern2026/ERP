<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnLine;
use App\Support\Contracts\JobService;
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
}
