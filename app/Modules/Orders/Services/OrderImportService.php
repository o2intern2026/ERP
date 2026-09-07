<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\ClientAddress;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Support\Contracts\DocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Owns the durable preview, grouping, duplicate protection and audit trail for A4. */
final class OrderImportService
{
    public function __construct(
        private readonly SpreadsheetManifestParser $parser,
        private readonly OrderCreationService $orders,
        private readonly DocumentService $documents,
    ) {}

    /** @param array{client_id:int,job_id:int,requested_date:string,service_level:string} $context */
    public function preview(UploadedFile $file, array $context, ?int $actorId): OrderImport
    {
        $path = $file->store('imports/orders', 'local');
        $sha256 = hash_file('sha256', Storage::disk('local')->path($path));

        $import = OrderImport::query()->create([
            'client_id' => $context['client_id'],
            'source' => 'excel',
            'status' => 'pending',
            'created_by' => $actorId,
        ]);

        $documentId = $this->documents->attach('packing_list', 'order_import', $import->id, $path, [
            'job_id' => $context['job_id'],
            'client_id' => $context['client_id'],
            'client_visible' => false,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by' => $actorId,
        ]);

        $parsed = $this->parser->parse(Storage::disk('local')->path($path));
        $previousFile = OrderImport::query()->where('client_id', $context['client_id'])->whereKeyNot($import->id)->get()
            ->first(fn (OrderImport $candidate) => data_get($candidate->errors, 'context.sha256') === $sha256);
        if ($previousFile !== null) {
            $parsed['warnings'][] = [
                'row' => 0,
                'column' => 'file',
                'message' => __('orders.imports.errors.duplicate_file', ['id' => $previousFile->id]),
            ];
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
        $created = [];
        $issues = $audit['issues'] ?? [];

        DB::transaction(function () use ($import, $selectedKeys, $saveAddressKeys, $actorId, &$audit, &$created, &$issues, $context): void {
            $locked = OrderImport::query()->lockForUpdate()->findOrFail($import->id);
            abort_unless($locked->status === 'pending', 409, __('orders.imports.errors.already_processed'));
            DB::table('jobs')->where('id', $context['job_id'])->lockForUpdate()->first();

            foreach ($audit['groups'] ?? [] as &$group) {
                if ($group['status'] !== 'ready' || ! in_array($group['key'], $selectedKeys, true)) {
                    continue;
                }

                if ($this->duplicateOrder($import->client_id, $group, $context['requested_date'])) {
                    $group['status'] = 'duplicate';
                    $group['message'] = __('orders.imports.errors.duplicate_order', ['mark' => $group['consignment_mark']]);
                    foreach ($group['row_numbers'] as $row) {
                        $issues[] = ['row' => $row, 'column' => 'consignment_mark', 'message' => $group['message']];
                    }
                    continue;
                }

                $addressId = $group['client_address_id'];
                if ($addressId === null && in_array($group['key'], $saveAddressKeys, true)) {
                    $addressId = $this->saveAddress($import->client_id, $group)->id;
                }

                $order = $this->orders->create([
                    'client_id' => $import->client_id,
                    'job_id' => $context['job_id'],
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
                    'requested_date' => $context['requested_date'],
                    'service_level' => $context['service_level'],
                    'lines' => $group['rows'],
                    'declared_packages' => array_map(fn ($row) => [
                        'package_type' => $row['package_type'],
                        'qty' => $row['carton_qty'],
                        'weight_kg' => $row['actual_weight_kg'],
                        'length_mm' => $row['length_mm'],
                        'width_mm' => $row['width_mm'],
                        'height_mm' => $row['height_mm'],
                    ], $group['rows']),
                ], $actorId, 'excel');

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
                'row_numbers' => $markRows->pluck('row')->map(fn ($row) => (int) $row)->values()->all(),
                'rows' => $markRows->values()->all(),
            ];

            if ($signatures->count() > 1) {
                $group['status'] = 'blocked';
                $group['message'] = __('orders.imports.errors.inconsistent_group', ['mark' => $first['consignment_mark']]);
            } elseif ($this->matchingAsnExists((int) $context['client_id'], (int) $context['job_id'], (string) $first['consignment_mark'])) {
                $group['status'] = 'asn_match';
                $group['message'] = __('orders.imports.errors.matching_asn', ['mark' => $first['consignment_mark']]);
            } elseif ($this->duplicateOrder((int) $context['client_id'], $group, (string) $context['requested_date'])) {
                $group['status'] = 'duplicate';
                $group['message'] = __('orders.imports.errors.duplicate_order', ['mark' => $first['consignment_mark']]);
            }

            $groups[] = $group;
        }

        return $groups;
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
