<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnImport;
use App\Support\Contracts\DocumentService;
use App\Support\Contracts\ManifestParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * B2b: 《需派送货物清单》 → asn_lines, one line per goods row (consignee fields kept on the line for B2c).
 * The parser is the shared ManifestParser (Orders, M3; Fake until then). Pre-check: the same consignment mark with a
 * different consignee / FBA reference is a warning so the forwarder can fix the list before the container lands.
 */
final class AsnImportService
{
    public function __construct(
        private readonly ManifestParser $parser,
        private readonly AsnService $asns,
        private readonly DocumentService $documents,
    ) {}

    public function import(Asn $asn, UploadedFile $file, ?string $containerNo = null): AsnImport
    {
        $path = $file->store('imports/asns', 'local');

        $documentId = $this->documents->attach('packing_list', 'asn', $asn->id, $path, [
            'job_id' => $asn->job_id, 'client_id' => $asn->client_id, 'client_visible' => false,
            'original_name' => $file->getClientOriginalName(), 'mime' => $file->getClientMimeType(), 'size_bytes' => $file->getSize(),
        ]);

        $parsed = $this->parser->parse(Storage::disk('local')->path($path));
        $warnings = array_merge($parsed['warnings'], $this->consistencyWarnings($parsed['rows']));

        return DB::transaction(function () use ($asn, $parsed, $warnings, $documentId, $containerNo): AsnImport {
            $lines = array_map(fn (array $row) => [
                'container_no' => $containerNo,
                'consignment_mark' => $row['consignment_mark'],
                'deliver_to_name' => $row['deliver_to_name'],
                'deliver_to_phone' => $row['deliver_to_phone'],
                'deliver_to_address' => $row['deliver_to_address'],
                'deliver_to_suburb' => $row['deliver_to_suburb'] ?? null, // OMS needs it to build orders from the ASN (createFromAsn completeness rule)
                'deliver_to_state' => $row['deliver_to_state'],
                'deliver_to_postcode' => $row['deliver_to_postcode'],
                'fba_reference' => $row['fba_reference'],
                'description' => trim(implode(' / ', array_filter([$row['description_cn'], $row['description_en']]))) ?: '—',
                'package_type' => $row['package_type'],
                'expected_cartons' => $row['carton_qty'],
                'weight_kg' => $row['actual_weight_kg'],
                'length_mm' => $row['length_mm'],
                'width_mm' => $row['width_mm'],
                'height_mm' => $row['height_mm'],
                'cbm' => $row['cbm'],
            ], $parsed['rows']);

            $this->asns->addLines($asn, $lines);

            return AsnImport::query()->create([
                'asn_id' => $asn->id,
                'client_id' => $asn->client_id,
                'job_id' => $asn->job_id,
                'document_id' => $documentId,
                'status' => $parsed['errors'] === [] ? 'imported' : 'failed',
                'row_count' => count($parsed['rows']),
                'error_count' => count($parsed['errors']),
                'errors' => $parsed['errors'],
                'warnings' => $warnings,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{row:int, column:string, message:string}>
     */
    private function consistencyWarnings(array $rows): array
    {
        $warnings = [];
        foreach (collect($rows)->filter(fn ($r) => ! empty($r['consignment_mark']))->groupBy('consignment_mark') as $mark => $group) {
            $signatures = $group->map(fn ($r) => implode('|', [$r['deliver_to_address'], $r['deliver_to_state'], $r['deliver_to_postcode'], $r['fba_reference']]))->unique();
            if ($signatures->count() > 1) {
                $warnings[] = ['row' => (int) $group->first()['row'], 'column' => 'consignment_mark', 'message' => "唛头 {$mark} 下的收件地址 / FBA 引用不一致,生成订单前需人工确认"];
            }
        }

        return $warnings;
    }
}
