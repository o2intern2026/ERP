<?php

namespace App\Modules\Platform\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CR #137 (audit ADMIN-10): the Document Centre asks for the number staff know — JOB- / ASN- / ORD- / SHP- or an invoice number —
 * and resolves it server side to the `documents` columns (related_type, related_id, job_id, client_id). Read-only lookups on the
 * other modules' tables by their unique number column (contracts/db-schema.md); a number nobody owns resolves to null.
 */
final class DocumentNumberResolver
{
    /** @var array<string, array{table: string, column: string, type: string}> prefix → where the number lives */
    private const PREFIXES = [
        'JOB-' => ['table' => 'jobs', 'column' => 'job_no', 'type' => 'job'],
        'ASN-' => ['table' => 'asns', 'column' => 'asn_no', 'type' => 'asn'],
        'ORD-' => ['table' => 'orders', 'column' => 'order_no', 'type' => 'order'],
        'SHP-' => ['table' => 'shipments', 'column' => 'shipment_no', 'type' => 'shipment'],
    ];

    /** @return list<string> the prefixes the form hint lists */
    public static function prefixes(): array
    {
        return array_keys(self::PREFIXES);
    }

    /** @return array{related_type: string, related_id: int, job_id: ?int, client_id: ?int, number: string}|null */
    public function resolve(string $number): ?array
    {
        $number = Str::upper(trim($number));
        if ($number === '') {
            return null;
        }

        foreach (self::PREFIXES as $prefix => $source) {
            if (Str::startsWith($number, $prefix)) {
                return $this->lookup($source['table'], $source['column'], $number, $source['type']);
            }
        }

        // Anything else is tried as an invoice number (INV-YYYYMM-NNNN once issued; a draft's placeholder number also resolves).
        return $this->lookup('invoices', 'invoice_no', $number, 'invoice');
    }

    /** @return array{related_type: string, related_id: int, job_id: ?int, client_id: ?int, number: string}|null */
    private function lookup(string $table, string $column, string $number, string $type): ?array
    {
        $row = DB::table($table)->where($column, $number)->first();
        if ($row === null) {
            return null;
        }

        $jobId = match ($type) {
            'job' => (int) $row->id,
            'invoice' => $this->invoiceJobId((int) $row->id), // an invoice spans its lines' Jobs; one Job when they agree, else none
            default => isset($row->job_id) ? (int) $row->job_id : null,
        };

        return [
            'related_type' => $type,
            'related_id' => (int) $row->id,
            'job_id' => $jobId,
            'client_id' => isset($row->client_id) ? (int) $row->client_id : null,
            'number' => $number,
        ];
    }

    private function invoiceJobId(int $invoiceId): ?int
    {
        $jobIds = DB::table('invoice_lines')->where('invoice_id', $invoiceId)->whereNotNull('job_id')->distinct()->pluck('job_id');

        return $jobIds->count() === 1 ? (int) $jobIds->first() : null;
    }
}
