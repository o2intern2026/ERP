<?php

namespace App\Modules\Portal\Services;

use App\Modules\Portal\Models\PortalAsnSubmission;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnImport;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\AsnImportService;
use App\Modules\Warehouse\Services\AsnService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * 客户自助预报入库 (lead request 2026-09-11, CHANGE_REQUESTS #116 — the CartonCloud / Extensiv pattern): the client uploads its
 * packing list with the container number and ETA, or its system pushes the same through the API, and the ASN is created at once
 * through Warehouse's own AsnService / AsnImportService — the very calls a coordinator's form makes — with created_by_type = client,
 * so it shows up for customer service as 待确认. No warehouse rule lives here: receiving, putaway and order generation are untouched.
 */
final class PortalAsnService
{
    /** asn_lines columns an API line may carry (anything else in the payload is ignored). */
    public const LINE_FIELDS = [
        'container_no', 'consignment_mark', 'description', 'expected_cartons', 'package_type', 'deliver_to_name', 'deliver_to_phone',
        'deliver_to_address', 'deliver_to_suburb', 'deliver_to_state', 'deliver_to_postcode', 'fba_reference', 'weight_kg', 'length_mm', 'width_mm', 'height_mm', 'cbm',
    ];

    public function __construct(private readonly AsnService $asns, private readonly AsnImportService $imports) {}

    /** Active warehouses a client may book into; the first is the form default. */
    public function warehouses(): Collection
    {
        return Warehouse::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    /**
     * Portal form: header + packing list in one submit.
     *
     * @param  array<string, mixed>  $data  validated form (warehouse_id, inbound_type, expected_date, reference, notes, container_no, container_size, unpack_mode, gross_weight_kg)
     * @return array{asn: Asn, import: AsnImport}
     */
    public function submitFromPortal(int $clientId, int $userId, array $data, UploadedFile $file): array
    {
        return DB::transaction(function () use ($clientId, $userId, $data, $file): array {
            $asn = $this->asns->create($this->header($clientId, $data));
            $import = $this->imports->import($asn, $file, filled($data['container_no'] ?? null) ? $data['container_no'] : null);
            PortalAsnSubmission::query()->create(['client_id' => $clientId, 'asn_id' => $asn->id, 'channel' => 'portal', 'user_id' => $userId, 'line_count' => $import->row_count, 'created_at' => now()]);

            return ['asn' => $asn->fresh(), 'import' => $import];
        });
    }

    /**
     * API push (Bearer token → client): the same header, the goods lines as JSON instead of a spreadsheet. An Idempotency-Key
     * replays the first ASN instead of creating a second one — also when two identical requests race on the unique key.
     *
     * @param  array<string, mixed>  $payload  validated body with warehouse_id resolved
     * @return array{asn: Asn, replayed: bool}
     */
    public function submitFromApi(int $clientId, ?int $tokenId, array $payload, ?string $idempotencyKey): array
    {
        $key = trim((string) $idempotencyKey) !== '' ? trim((string) $idempotencyKey) : null;
        if ($key !== null && ($existing = $this->replay($clientId, $key)) !== null) {
            return ['asn' => $existing, 'replayed' => true];
        }

        try {
            $asn = DB::transaction(function () use ($clientId, $tokenId, $payload, $key): Asn {
                $containers = collect($payload['containers'] ?? [])->map(fn (array $c): array => [
                    'container_no' => $c['container_no'], 'size' => $c['size'], 'unpack_mode' => $c['unpack_mode'] ?? 'loose', 'gross_weight_kg' => $c['gross_weight_kg'] ?? null,
                ])->values()->all();
                $asn = $this->asns->create($this->header($clientId, $payload) + ['containers' => $containers]);
                $lines = collect($payload['lines'])->map(fn (array $l): array => Arr::only($l, self::LINE_FIELDS))->values()->all();
                $this->asns->addLines($asn, $lines);
                PortalAsnSubmission::query()->create(['client_id' => $clientId, 'asn_id' => $asn->id, 'channel' => 'api', 'idempotency_key' => $key, 'token_id' => $tokenId, 'line_count' => count($lines), 'created_at' => now()]);

                return $asn;
            });
        } catch (QueryException $e) {
            $existing = $key !== null ? $this->replay($clientId, $key) : null;
            if ($existing === null) {
                throw $e;
            }

            return ['asn' => $existing, 'replayed' => true];
        }

        return ['asn' => $asn->fresh(), 'replayed' => false];
    }

    /** 补传装箱单 while the submission is still a draft: AsnService drops the untouched lines (and refuses once anything was received), the new list replaces them. */
    public function replacePackingList(Asn $asn, UploadedFile $file, ?string $containerNo): AsnImport
    {
        return DB::transaction(function () use ($asn, $file, $containerNo): AsnImport {
            $this->asns->clearDraftLines($asn);

            return $this->imports->import($asn, $file, $containerNo);
        });
    }

    private function replay(int $clientId, string $key): ?Asn
    {
        $existing = PortalAsnSubmission::query()->withoutGlobalScopes()->where('client_id', $clientId)->where('idempotency_key', $key)->first();

        return $existing === null ? null : Asn::query()->withoutGlobalScopes()->findOrFail($existing->asn_id);
    }

    /** @param array<string, mixed> $data */
    private function header(int $clientId, array $data): array
    {
        $header = [
            'client_id' => $clientId,
            'warehouse_id' => (int) $data['warehouse_id'],
            'inbound_type' => $data['inbound_type'],
            'expected_date' => $data['expected_date'] ?? null,
            'reference' => filled($data['reference'] ?? null) ? $data['reference'] : null,
            'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null,
            'created_by_type' => 'client',
        ];
        if ($data['inbound_type'] === 'container' && filled($data['container_no'] ?? null)) {
            $header['containers'] = [[
                'container_no' => $data['container_no'], 'size' => $data['container_size'] ?? '40', 'unpack_mode' => $data['unpack_mode'] ?? 'loose',
                'gross_weight_kg' => filled($data['gross_weight_kg'] ?? null) ? $data['gross_weight_kg'] : null,
            ]];
        }

        return $header;
    }
}
