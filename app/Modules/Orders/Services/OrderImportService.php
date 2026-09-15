<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\ClientAddress;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Support\Contracts\DocumentService;
use App\Support\Contracts\JobService;
use App\Support\Contracts\RateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Owns the durable preview, grouping, duplicate protection and audit trail for A4 — the staff 批量导入 and, since
 * CHANGE_REQUESTS #123, the client's own 入库清单 upload in the portal (source `portal`): the same parser, grouping and
 * confirm path; the portal submission carries no Job up front (one loose Job is opened with its first order at confirm)
 * and keeps the client's inbound context (柜号 / 柜型 / 预计到港 / 参考号 / 备注) in `errors.context.inbound` for 待建预报.
 */
final class OrderImportService
{
    public function __construct(
        private readonly SpreadsheetManifestParser $parser,
        private readonly OrderCreationService $orders,
        private readonly DocumentService $documents,
        private readonly JobService $jobs,
        private readonly RateService $rates,
    ) {}

    /**
     * @param  array{client_id:int, job_id?:?int, requested_date:string, service_level:string, source?:string, client_visible?:bool, inbound?:?array<string, mixed>}  $context
     */
    public function preview(UploadedFile $file, array $context, ?int $actorId): OrderImport
    {
        $context += ['job_id' => null, 'source' => 'excel', 'client_visible' => false, 'inbound' => null];
        $path = $file->store('imports/orders', 'local');
        $sha256 = hash_file('sha256', Storage::disk('local')->path($path));

        $import = OrderImport::query()->create([
            'client_id' => $context['client_id'],
            'source' => $context['source'],
            'status' => 'pending',
            'created_by' => $actorId,
        ]);

        $documentId = $this->documents->attach('packing_list', 'order_import', $import->id, $path, array_filter([
            'job_id' => $context['job_id'],
            'client_id' => $context['client_id'],
            'client_visible' => (bool) $context['client_visible'], // the client's own upload stays downloadable in the portal
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by' => $actorId,
        ], fn ($value) => $value !== null));

        $parsed = $this->parser->parse(Storage::disk('local')->path($path));
        $parsed['rows'] = $this->storageTiers($parsed['rows'], (int) $context['client_id'], $context['source'] === 'portal' ? 'client' : 'staff', $parsed['warnings']);
        $previousFile = OrderImport::query()->where('client_id', $context['client_id'])->whereKeyNot($import->id)->get()
            ->first(fn (OrderImport $candidate) => data_get($candidate->errors, 'context.sha256') === $sha256);
        if ($previousFile !== null) {
            array_unshift($parsed['warnings'], [
                'row' => 0,
                'column' => 'file',
                'label' => __('orders.imports.columns.file'),
                'message' => __('orders.imports.errors.duplicate_file', ['id' => $previousFile->id]),
            ]);
        }
        $groups = $this->groups($parsed['rows'], $context, $sha256);
        $failedRows = count(array_unique(array_merge(
            array_column($parsed['errors'], 'row'),
            collect($groups)->whereIn('status', ['blocked', 'duplicate', 'asn_match'])->flatMap(fn ($group) => $group['row_numbers'])->all(),
        )));

        $audit = [
            'context' => $context + ['sha256' => $sha256, 'original_name' => $file->getClientOriginalName()],
            'issues' => $parsed['errors'],
            'warnings' => $parsed['warnings'],
            'raw_rows' => $parsed['raw_rows'] ?? [],
            'groups' => $groups,
            'result' => ['created' => [], 'failed_rows' => $failedRows],
        ];

        $import->update([
            'document_id' => $documentId,
            'row_count' => count($parsed['rows']) + count(array_unique(array_column($parsed['errors'], 'row'))),
            'error_count' => $failedRows,
            'errors' => $audit,
        ]);

        return $import->fresh();
    }

    /** @param list<string> $selectedKeys @param list<string> $saveAddressKeys */
    public function confirm(OrderImport $import, array $selectedKeys, array $saveAddressKeys, ?int $actorId): OrderImport
    {
        abort_unless($import->status === 'pending', 409, __('orders.imports.errors.already_processed'));
        $audit = $import->errors ?? [];
        $context = $audit['context'];
        $source = $import->source === 'portal' ? 'portal' : 'excel';
        $created = [];
        $issues = $audit['issues'] ?? [];

        DB::transaction(function () use ($import, $selectedKeys, $saveAddressKeys, $actorId, $source, &$audit, &$created, &$issues, $context): void {
            $locked = OrderImport::query()->lockForUpdate()->findOrFail($import->id);
            abort_unless($locked->status === 'pending', 409, __('orders.imports.errors.already_processed'));
            $jobId = filled($context['job_id'] ?? null) ? (int) $context['job_id'] : null;
            if ($jobId !== null) {
                DB::table('jobs')->where('id', $jobId)->lockForUpdate()->first();
            }

            foreach ($audit['groups'] ?? [] as &$group) {
                if ($group['status'] !== 'ready' || ! in_array($group['key'], $selectedKeys, true)) {
                    continue;
                }
                $requestedDate = (string) ($group['requested_date'] ?? $context['requested_date']);

                if ($this->duplicateOrder($import->client_id, $group, $requestedDate)) {
                    $group['status'] = 'duplicate';
                    $group['message'] = __('orders.imports.errors.duplicate_order', ['mark' => $group['consignment_mark']]);
                    foreach ($group['row_numbers'] as $row) {
                        $issues[] = ['row' => $row, 'column' => 'consignment_mark', 'label' => __('orders.imports.columns.consignment_mark'), 'message' => $group['message']];
                    }

                    continue;
                }

                $addressId = $group['client_address_id'];
                if ($addressId === null && in_array($group['key'], $saveAddressKeys, true)) {
                    $addressId = $this->saveAddress($import->client_id, $group)->id;
                }

                // One Job per submission (CHANGE_REQUESTS #123): opened with the first order, so an abandoned or fully blocked
                // upload never leaves an empty Job behind; every order of the list then shares the Job the ASN will join.
                if ($jobId === null) {
                    $jobId = $this->jobs->create((int) $import->client_id, 'loose', array_filter([
                        'reference' => $this->jobReference($context),
                        'notes' => __('orders.imports.job_note', ['id' => $import->id, 'file' => (string) ($context['original_name'] ?? '')]),
                    ]))['job_id'];
                    $audit['context']['job_id'] = $jobId;
                    DB::table('jobs')->where('id', $jobId)->lockForUpdate()->first();
                }

                $order = $this->orders->create([
                    'client_id' => $import->client_id,
                    'job_id' => $jobId,
                    'order_type' => 'from_stock',
                    'external_ref' => $group['external_ref'],
                    'consignment_mark' => $group['consignment_mark'],
                    'fba_reference' => $group['fba_reference'],
                    'deliver_to_name' => $group['deliver_to_name'],
                    'deliver_to_phone' => $group['deliver_to_phone'],
                    'deliver_to_address' => $group['deliver_to_address'],
                    'deliver_to_suburb' => $group['deliver_to_suburb'],
                    'deliver_to_state' => $group['deliver_to_state'],
                    'deliver_to_postcode' => $group['deliver_to_postcode'],
                    'deliver_to_address_type' => $group['deliver_to_address_type'],
                    'delivery_instructions' => $group['delivery_instructions'],
                    'client_address_id' => $addressId,
                    'requested_date' => $requestedDate,
                    'service_level' => (string) ($group['service_level'] ?? $context['service_level']),
                    'lines' => $group['rows'],
                    // Declared packages mirror the goods lines (lead feedback 2026-09-14): qty = 箱数, weight = the line total ÷ 箱数 (单件重量).
                    'declared_packages' => array_map(fn ($row) => [
                        'package_type' => $row['package_type'],
                        'qty' => $row['carton_qty'],
                        'weight_kg' => $this->perPieceWeight($row),
                        'length_mm' => $row['length_mm'],
                        'width_mm' => $row['width_mm'],
                        'height_mm' => $row['height_mm'],
                    ], $group['rows']),
                ], $actorId, $source);

                $group['status'] = 'imported';
                $group['order_id'] = $order->id;
                $created[] = ['order_id' => $order->id, 'order_no' => $order->order_no, 'row_numbers' => $group['row_numbers']];
            }
            unset($group);

            $failedRows = count(array_unique(array_merge(
                array_column($issues, 'row'),
                collect($audit['groups'] ?? [])->whereIn('status', ['blocked', 'duplicate', 'asn_match'])->flatMap(fn ($group) => $group['row_numbers'])->all(),
            )));
            $audit['issues'] = $issues;
            $audit['result'] = ['created' => $created, 'failed_rows' => $failedRows];
            $locked->update([
                'status' => $created === [] ? 'failed' : 'imported',
                'error_count' => $failedRows,
                'errors' => $audit,
            ]);
        });

        return $import->fresh();
    }

    /** @param list<array<string,mixed>> $rows @param array<string,mixed> $context @return list<array<string,mixed>> */
    private function groups(array $rows, array $context, string $sha256): array
    {
        $groups = [];
        $jobId = filled($context['job_id'] ?? null) ? (int) $context['job_id'] : null;
        foreach (collect($rows)->groupBy(fn ($row) => mb_strtolower(trim((string) $row['consignment_mark']))) as $markRows) {
            $first = $markRows->first();
            $signatures = $markRows->map(fn ($row) => $this->signature($row))->unique();
            $key = hash('sha256', $sha256.'|'.$first['consignment_mark'].'|'.$this->signature($first));
            $address = $this->matchingAddress((int) $context['client_id'], $first);
            $group = [
                'key' => $key,
                'status' => 'ready',
                'message' => null,
                'consignment_mark' => $first['consignment_mark'],
                'external_ref' => collect($markRows)->pluck('external_ref')->filter()->first(),
                'fba_reference' => $first['fba_reference'],
                'deliver_to_name' => $first['deliver_to_name'],
                'deliver_to_phone' => $first['deliver_to_phone'],
                'deliver_to_address' => $first['deliver_to_address'],
                'deliver_to_suburb' => $first['deliver_to_suburb'],
                'deliver_to_state' => $first['deliver_to_state'],
                'deliver_to_postcode' => $first['deliver_to_postcode'],
                'deliver_to_address_type' => $address?->address_type ?? ($first['fba_reference'] ? 'fba' : 'business'),
                'delivery_instructions' => $address?->default_instructions,
                'client_address_id' => $address?->id,
                'save_address_suggested' => $address === null,
                // A 要求送达日 / 服务等级 column on the sheet wins over the form / portal default (first row of the mark that carries one).
                'requested_date' => collect($markRows)->pluck('requested_date')->filter()->first(),
                'service_level' => collect($markRows)->pluck('service_level')->filter()->first(),
                'row_numbers' => $markRows->pluck('row')->map(fn ($row) => (int) $row)->values()->all(),
                'rows' => $markRows->values()->all(),
            ];
            $requestedDate = (string) ($group['requested_date'] ?? $context['requested_date']);

            if ($signatures->count() > 1) {
                $group['status'] = 'blocked';
                $group['message'] = __('orders.imports.errors.inconsistent_group', ['mark' => $first['consignment_mark']]);
            } elseif ($jobId !== null && $this->matchingAsnExists((int) $context['client_id'], $jobId, (string) $first['consignment_mark'])) {
                $group['status'] = 'asn_match';
                $group['message'] = __('orders.imports.errors.matching_asn', ['mark' => $first['consignment_mark']]);
            } elseif ($this->duplicateOrder((int) $context['client_id'], $group, $requestedDate)) {
                $group['status'] = 'duplicate';
                $group['message'] = __('orders.imports.errors.duplicate_order', ['mark' => $first['consignment_mark']]);
            }

            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * CHANGE_REQUESTS #126 存储等级: a declared cell keeps its tier with source `client` (portal) / `staff` (staff import). Without a
     * declaration, a row whose declared unit price (单价, cents) reaches the client's `tier_value_threshold_cents` — read from the
     * WH-STORAGE-TIER-PLT-WK rate item through RateService::thresholds; absent → no pre-fill — is pre-filled `bottom` with source
     * `value_rule` and a warning. The value never prices anything (lead answer 4); the stored declaration is what counts.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  list<array<string,mixed>>  $warnings
     * @return list<array<string,mixed>>
     */
    private function storageTiers(array $rows, int $clientId, string $declaredBy, array &$warnings): array
    {
        $threshold = $this->rates->thresholds($clientId, 'WH-STORAGE-TIER-PLT-WK')['tier_value_threshold_cents'] ?? null;
        foreach ($rows as &$row) {
            $row['storage_tier_source'] = ! empty($row['storage_tier_declared']) ? $declaredBy : null;
            if ($row['storage_tier_source'] === null && is_numeric($threshold) && isset($row['unit_price_cents']) && (int) $row['unit_price_cents'] >= (int) $threshold) {
                $row['storage_tier'] = 'bottom';
                $row['storage_tier_source'] = 'value_rule';
                $warnings[] = ['row' => (int) $row['row'], 'column' => 'storage_tier', 'label' => __('orders.imports.columns.storage_tier'), 'message' => __('orders.imports.warnings.tier_value_prefill', ['row' => $row['row']])];
            }
        }
        unset($row);

        return $rows;
    }

    /** @param array<string,mixed> $group */
    private function duplicateOrder(int $clientId, array $group, string $requestedDate): bool
    {
        return Order::query()->withoutGlobalScopes()->where('client_id', $clientId)
            ->where(function ($query) use ($group, $requestedDate) {
                if (filled($group['external_ref'])) {
                    $query->where('external_ref', $group['external_ref']);
                } else {
                    $query->where('consignment_mark', $group['consignment_mark'])
                        ->where('deliver_to_name', $group['deliver_to_name'])
                        ->whereDate('requested_date', $requestedDate);
                }
            })->exists();
    }

    private function matchingAsnExists(int $clientId, int $jobId, string $mark): bool
    {
        return DB::table('asn_lines')->join('asns', 'asns.id', '=', 'asn_lines.asn_id')
            ->where('asns.client_id', $clientId)->where('asns.job_id', $jobId)
            ->whereRaw('LOWER(asn_lines.consignment_mark) = ?', [mb_strtolower(trim($mark))])->exists();
    }

    /** @param array<string,mixed> $row */
    private function matchingAddress(int $clientId, array $row): ?ClientAddress
    {
        $wanted = $this->addressKey($row['deliver_to_address'], $row['deliver_to_state'], $row['deliver_to_postcode']);

        return ClientAddress::query()->where('client_id', $clientId)->get()
            ->first(fn ($address) => $this->addressKey($address->address, $address->state, $address->postcode) === $wanted);
    }

    /** @param array<string,mixed> $group */
    private function saveAddress(int $clientId, array $group): ClientAddress
    {
        return ClientAddress::query()->firstOrCreate([
            'client_id' => $clientId,
            'address' => $group['deliver_to_address'],
            'state' => $group['deliver_to_state'],
            'postcode' => $group['deliver_to_postcode'],
        ], [
            'label' => $group['deliver_to_name'],
            'contact_name' => $group['deliver_to_name'],
            'phone' => $group['deliver_to_phone'],
            'suburb' => $group['deliver_to_suburb'],
            'address_type' => $group['deliver_to_address_type'],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function perPieceWeight(array $row): ?float
    {
        $total = $row['actual_weight_kg'] ?? null;
        $qty = (int) ($row['carton_qty'] ?? 0);

        return $total === null || $qty <= 0 ? $total : round((float) $total / $qty, 3);
    }

    /** The Job's reference for a portal submission: the client's 参考号, else the 柜号, else nothing. */
    private function jobReference(array $context): ?string
    {
        $inbound = is_array($context['inbound'] ?? null) ? $context['inbound'] : [];
        foreach (['reference', 'container_no'] as $field) {
            if (filled($inbound[$field] ?? null)) {
                return mb_substr((string) $inbound[$field], 0, 100);
            }
        }

        return null;
    }

    /** @param array<string,mixed> $row */
    private function signature(array $row): string
    {
        return mb_strtolower(implode('|', array_map(fn ($value) => trim((string) $value), [
            $row['deliver_to_name'], $row['deliver_to_address'], $row['deliver_to_state'],
            $row['deliver_to_postcode'], $row['fba_reference'],
        ])));
    }

    private function addressKey(?string $address, ?string $state, ?string $postcode): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', (string) $address).'|'.trim((string) $state).'|'.trim((string) $postcode));
    }
}
