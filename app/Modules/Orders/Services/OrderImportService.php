<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\ClientAddress;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Support\Contracts\DocumentService;
use App\Support\Contracts\JobService;
use App\Support\Contracts\RateService;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Owns the durable preview, grouping, duplicate protection and audit trail for A4 — the staff 批量导入 and, since
 * CHANGE_REQUESTS #123, the client's own 入库清单 upload in the portal (source `portal`): the same parser, grouping and
 * confirm path; the portal submission carries no Job up front (one loose Job is opened with its first order at confirm)
 * and keeps the client's inbound context (柜号 / 柜型 / 预计到港 / 参考号 / 备注) in `errors.context.inbound` for 待建预报.
 *
 * CHANGE_REQUESTS #128 手工建立入库清单: the same submission can be typed on the portal page (previewRows — rows through
 * ManifestParser::fromRows, no file, no Document) and can ATTACH the client's existing orders that still wait for an ASN
 * (attachableOrders). 以订单为准: an attached order is never read into a group nor written — the list only records its id
 * (`context.manual.attached_order_ids`, then `result.attached`) so 待建预报 sees created + attached orders as ONE submission
 * (OrderImport::orderIds). Drafts (`status = draft`) keep the typed rows, ticks and context until the client submits them.
 *
 * CHANGE_REQUESTS #136 (audit PORTAL-05 / PORTAL-07): a 唛头 with a row the parser refused is `blocked` (`error_rows`, message
 * naming the rows) — an order is never generated short of a goods line; the group's `deliver_to_address_type` comes from the
 * list's 地址类型 column when the row carries one, else the address-book match, else the FBA-reference / business inference.
 *
 * CHANGE_REQUESTS #143 (the client's consolidation list): two IMPORT OPTIONS live in `context` (never on a parser Row) —
 * `group_by` = `mark` (today's rule) | `recipient` (rows with the same recipient name + postcode + address become ONE order whose
 * 唛头 is the first row's waybill root, i.e. the waybill without a trailing "-<n>", every row keeping its own waybill in front of
 * its description as "<waybill> · <commodity>" so unit labels and the pick list show it) and `address_type_default` = `auto`
 * (today's rule) | `residential` | `business` for rows without an explicit 地址类型 value. Both are recorded with the import and
 * shown on the preview together with the resulting order count.
 *
 * CHANGE_REQUESTS #144 (import from 新建订单 for both order types): `context.order_type` = `from_stock` (today's inbound list) or
 * `pickup_deliver` (现场提货直送). A 提货直送 list carries the pickup party in `context.pickup` instead of an inbound context, never
 * declares a storage tier, opens ONE transport_only Job per submission and makes `pickup_deliver` orders sharing the list's pickup
 * address, every row a declared package of its own weight (the parser already refuses a row without a positive weight, for every
 * list — the portal order form requires it for that type too) — no ASN, nothing for 待建预报.
 */
final class OrderImportService
{
    /** CHANGE_REQUESTS #144: the order type a list produces — `from_stock` (inbound list, ASN by customer service) or `pickup_deliver` (现场提货直送). */
    public const ORDER_TYPES = ['from_stock', 'pickup_deliver'];

    /** CHANGE_REQUESTS #143: how rows become orders — by 唛头 / waybill (default) or by recipient (name + postcode + address). */
    public const GROUP_BY = ['mark', 'recipient'];

    /** CHANGE_REQUESTS #143: the address type of rows without an explicit 地址类型 — `auto` = address book → FBA reference → business. */
    public const ADDRESS_TYPE_DEFAULTS = ['auto', 'residential', 'business'];

    public function __construct(
        private readonly SpreadsheetManifestParser $parser,
        private readonly OrderCreationService $orders,
        private readonly DocumentService $documents,
        private readonly JobService $jobs,
        private readonly RateService $rates,
        private readonly OrderInboundService $inbound,
    ) {}

    /**
     * @param  array{client_id:int, job_id?:?int, requested_date:string, service_level:string, source?:string, client_visible?:bool, inbound?:?array<string, mixed>, group_by?:string, address_type_default?:string, order_type?:string, pickup?:?array<string, string>}  $context
     */
    public function preview(UploadedFile $file, array $context, ?int $actorId): OrderImport
    {
        $context += ['job_id' => null, 'source' => 'excel', 'client_visible' => false, 'inbound' => null];
        $context = $this->withOptions($context);
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
        $previousFile = OrderImport::query()->where('client_id', $context['client_id'])->whereKeyNot($import->id)->get()
            ->first(fn (OrderImport $candidate) => data_get($candidate->errors, 'context.sha256') === $sha256);
        $fileWarning = $previousFile === null ? null : [
            'row' => 0,
            'column' => 'file',
            'label' => __('orders.imports.columns.file'),
            'message' => __('orders.imports.errors.duplicate_file', ['id' => $previousFile->id]),
        ];
        $context += ['sha256' => $sha256, 'original_name' => $file->getClientOriginalName()];

        return $this->record($import, $parsed, $context, $sha256, $fileWarning, ['document_id' => $documentId]);
    }

    /**
     * CHANGE_REQUESTS #128: the portal 手工建立入库清单 — rows typed on the page (ManifestParser::fromRows) plus the client's existing
     * orders ticked to be attached, through the SAME pipeline as preview(): storage tiers, grouping, duplicate protection, audit.
     * `sha256` is the hash of the normalised rows + the sorted attached ids (no file, so no duplicate-file warning); the audit adds
     * `context.manual = {rows, attached_order_ids}`, `original_name` null, `document_id` null. Every attached id must be attachable
     * (attachableOrders) — else a Chinese RuleViolation naming the order and NOTHING is stored. With `$draft` (the client's own draft)
     * that row is reused and moved to pending; otherwise a new portal import is opened.
     *
     * @param  list<array<string, mixed>>  $rows  the posted rows, keyed by SpreadsheetManifestParser::FORM_FIELDS
     * @param  list<int>  $attachedOrderIds
     * @param  array{client_id:int, requested_date:string, service_level:string, inbound?:?array<string, mixed>, group_by?:string, address_type_default?:string}  $context
     */
    public function previewRows(array $rows, array $attachedOrderIds, array $context, ?int $actorId, ?OrderImport $draft = null): OrderImport
    {
        $context += ['job_id' => null, 'source' => 'portal', 'client_visible' => false, 'inbound' => null];
        $context = $this->withOptions($context);
        $clientId = (int) $context['client_id'];
        $rows = array_values($rows);
        $attached = $this->checkAttachable($clientId, $attachedOrderIds, $draft?->id);

        $parsed = $this->parser->fromRows($rows);
        $sorted = $attached;
        sort($sorted);
        $sha256 = hash('sha256', json_encode($parsed['rows'], JSON_UNESCAPED_UNICODE).'|'.implode(',', $sorted));

        $import = $this->manualImport($clientId, $actorId, $draft);
        $context['sha256'] = $sha256;
        $context['original_name'] = null;
        $context['manual'] = ['rows' => $rows, 'attached_order_ids' => $attached];

        return $this->record($import, $parsed, $context, $sha256, null, ['document_id' => null]);
    }

    /**
     * CHANGE_REQUESTS #128: 保存草稿 — the typed rows, the ticked orders and the inbound context are kept as posted (no parsing, no
     * groups, no result) under `status = draft`, to be reopened and submitted later. The attached ids are still checked so a draft
     * never pins another client's order or one already in a submission. Reuses `$draft` (the client's own draft) or opens a new row.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $attachedOrderIds
     * @param  array{client_id:int, requested_date:string, service_level:string, inbound?:?array<string, mixed>, group_by?:string, address_type_default?:string}  $context
     */
    public function saveDraft(array $rows, array $attachedOrderIds, array $context, ?int $actorId, ?OrderImport $draft = null): OrderImport
    {
        $context += ['job_id' => null, 'source' => 'portal', 'client_visible' => false, 'inbound' => null];
        $context = $this->withOptions($context);
        $clientId = (int) $context['client_id'];
        $attached = $this->checkAttachable($clientId, $attachedOrderIds, $draft?->id);

        $import = $this->manualImport($clientId, $actorId, $draft);
        $context['sha256'] = null;
        $context['original_name'] = null;
        $context['manual'] = ['rows' => array_values($rows), 'attached_order_ids' => $attached];
        $import->update([
            'status' => 'draft',
            'document_id' => null,
            'row_count' => count(array_filter($rows, fn ($row) => is_array($row) && array_filter($row, fn ($value) => filled($value)) !== [])),
            'error_count' => 0,
            'errors' => ['context' => $context, 'issues' => [], 'warnings' => []],
        ]);

        return $import->fresh();
    }

    /**
     * CHANGE_REQUESTS #128: the client's orders that may be attached to a manual list — every 待建预报 candidate of the client
     * (OrderInboundService::candidates: from_stock, received / confirmed, a goods line without an ASN line) MINUS the orders that
     * already sit in another portal submission: created or attached by a confirmed one (`result.created` / `result.attached`), or
     * ticked on a draft / pending manual list (`context.manual.attached_order_ids`). `$exceptImportId` = the submission being edited
     * or confirmed, whose own ticks must not count against it.
     *
     * @return Collection<int, Order>
     */
    public function attachableOrders(int $clientId, ?int $exceptImportId = null): Collection
    {
        $taken = $this->takenOrderIds($clientId, $exceptImportId);

        return $this->inbound->candidates($clientId)->reject(fn (Order $order) => isset($taken[(int) $order->id]))->values();
    }

    /** @param list<string> $selectedKeys @param list<string> $saveAddressKeys */
    public function confirm(OrderImport $import, array $selectedKeys, array $saveAddressKeys, ?int $actorId): OrderImport
    {
        abort_unless($import->status === 'pending', 409, __('orders.imports.errors.already_processed'));
        $audit = $import->errors ?? [];
        $context = $audit['context'];
        $source = $import->source === 'portal' ? 'portal' : 'excel';
        $manual = is_array($context['manual'] ?? null);
        $created = [];
        $attached = [];
        $issues = $audit['issues'] ?? [];

        DB::transaction(function () use ($import, $selectedKeys, $saveAddressKeys, $actorId, $source, $manual, &$audit, &$created, &$attached, &$issues, $context): void {
            $locked = OrderImport::query()->lockForUpdate()->findOrFail($import->id);
            abort_unless($locked->status === 'pending', 409, __('orders.imports.errors.already_processed'));
            $jobId = filled($context['job_id'] ?? null) ? (int) $context['job_id'] : null;
            if ($jobId !== null) {
                DB::table('jobs')->where('id', $jobId)->lockForUpdate()->first();
            }
            // CHANGE_REQUESTS #144: a 提货直送 list makes pickup_deliver orders that share the list's pickup party; its Job is transport_only.
            $orderType = in_array($context['order_type'] ?? null, self::ORDER_TYPES, true) ? $context['order_type'] : 'from_stock';
            $pickupAddress = $orderType === 'pickup_deliver' && is_array($context['pickup'] ?? null) ? $this->pickupAddress($context['pickup']) : null;
            // CHANGE_REQUESTS #128 以订单为准: the ticked orders are row-locked and re-checked (still the client's, still waiting for an ASN,
            // still in no other submission) — recorded by id only, never read into a group nor written.
            if ($manual) {
                $attached = $this->lockAttached((int) $import->client_id, $context['manual']['attached_order_ids'] ?? [], (int) $import->id);
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
                    $jobId = $this->jobs->create((int) $import->client_id, $orderType === 'pickup_deliver' ? 'transport_only' : 'loose', array_filter([
                        'reference' => $this->jobReference($context),
                        'notes' => __('orders.imports.job_note', ['id' => $import->id, 'file' => (string) ($context['original_name'] ?? __('orders.imports.manual_entry'))]),
                    ]))['job_id'];
                    $audit['context']['job_id'] = $jobId;
                    DB::table('jobs')->where('id', $jobId)->lockForUpdate()->first();
                }

                $order = $this->orders->create([
                    'client_id' => $import->client_id,
                    'job_id' => $jobId,
                    'order_type' => $orderType,
                    'external_ref' => $group['external_ref'],
                    'consignment_mark' => $group['consignment_mark'],
                    'fba_reference' => $group['fba_reference'],
                    'pickup_address' => $pickupAddress, // CHANGE_REQUESTS #144: null for a from_stock list
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
            $audit['result'] = ['created' => $created, 'failed_rows' => $failedRows] + ($manual ? ['attached' => $attached] : []);
            $locked->update([
                'status' => $created === [] && $attached === [] ? 'failed' : 'imported',
                'error_count' => $failedRows,
                'errors' => $audit,
            ]);
        });

        return $import->fresh();
    }

    /**
     * The one pipeline behind preview() and previewRows(): storage tiers, an optional file-level warning first, grouping, failed-row
     * count, audit, and the import moved to pending with its counts.
     *
     * @param  array{rows:list<array<string, mixed>>, errors:list<array<string, mixed>>, warnings:list<array<string, mixed>>, raw_rows?:list<array<string, mixed>>}  $parsed
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $fileWarning
     * @param  array<string, mixed>  $columns  extra columns written with the audit (document_id)
     */
    private function record(OrderImport $import, array $parsed, array $context, string $sha256, ?array $fileWarning, array $columns = []): OrderImport
    {
        // CHANGE_REQUESTS #144: a 提货直送 list stores nothing — no tier declaration, no value-rule pre-fill, no tier warning.
        $parsed['rows'] = ($context['order_type'] ?? 'from_stock') === 'pickup_deliver'
            ? array_map(fn (array $row) => ['storage_tier' => null, 'storage_tier_source' => null] + $row, $parsed['rows'])
            : $this->storageTiers($parsed['rows'], (int) $context['client_id'], $context['source'] === 'portal' ? 'client' : 'staff', $parsed['warnings']);
        if ($fileWarning !== null) {
            array_unshift($parsed['warnings'], $fileWarning);
        }
        $groups = $this->groups($parsed['rows'], $parsed['errors'], $context, $sha256);
        $failedRows = count(array_unique(array_merge(
            array_column($parsed['errors'], 'row'),
            collect($groups)->whereIn('status', ['blocked', 'duplicate', 'asn_match'])->flatMap(fn ($group) => $group['row_numbers'])->all(),
        )));

        $audit = [
            'context' => $context,
            'issues' => $parsed['errors'],
            'warnings' => $parsed['warnings'],
            'raw_rows' => $parsed['raw_rows'] ?? [],
            'groups' => $groups,
            'result' => ['created' => [], 'failed_rows' => $failedRows],
        ];

        $import->update($columns + [
            'status' => 'pending',
            'row_count' => count($parsed['rows']) + count(array_unique(array_column($parsed['errors'], 'row'))),
            'error_count' => $failedRows,
            'errors' => $audit,
        ]);

        return $import->fresh();
    }

    /**
     * CHANGE_REQUESTS #143: the two import options with their defaults — an unknown value falls back to today's rule so an old
     * context (or a hand-edited request) never changes how a list is grouped.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withOptions(array $context): array
    {
        $context['group_by'] = in_array($context['group_by'] ?? null, self::GROUP_BY, true) ? $context['group_by'] : 'mark';
        $context['address_type_default'] = in_array($context['address_type_default'] ?? null, self::ADDRESS_TYPE_DEFAULTS, true) ? $context['address_type_default'] : 'auto';
        $context['order_type'] = in_array($context['order_type'] ?? null, self::ORDER_TYPES, true) ? $context['order_type'] : 'from_stock'; // CHANGE_REQUESTS #144

        return $context;
    }

    /**
     * CHANGE_REQUESTS #144: the pickup party of a 提货直送 list in the shape `orders.pickup_address` carries (the portal order form's
     * pickup_* fields): name, phone, address, suburb, state, postcode — trimmed, the state upper-cased.
     *
     * @param  array<string, mixed>  $pickup
     * @return array{name:string, phone:string, address:string, suburb:string, state:string, postcode:string}
     */
    private function pickupAddress(array $pickup): array
    {
        $field = fn (string $key): string => trim((string) ($pickup[$key] ?? ''));

        return [
            'name' => $field('name'),
            'phone' => $field('phone'),
            'address' => $field('address'),
            'suburb' => $field('suburb'),
            'state' => mb_strtoupper($field('state')),
            'postcode' => $field('postcode'),
        ];
    }

    /** The row a manual submission lives on: the client's own draft (reused), else a new portal import. */
    private function manualImport(int $clientId, ?int $actorId, ?OrderImport $draft): OrderImport
    {
        if ($draft === null) {
            return OrderImport::query()->create([
                'client_id' => $clientId,
                'source' => 'portal',
                'status' => 'pending',
                'created_by' => $actorId,
            ]);
        }
        if ($draft->status !== 'draft' || (int) $draft->client_id !== $clientId || $draft->source !== 'portal') {
            throw new InvalidArgumentException("Import {$draft->id} is not a draft of client {$clientId}.");
        }

        return $draft;
    }

    /**
     * The posted attached ids, de-duplicated and every one checked against attachableOrders(): an id that is not the client's own
     * order is refused by number, one that is the client's but no longer attachable (an ASN exists, or another submission holds it)
     * by order number — in Chinese, and nothing is stored by the caller.
     *
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function checkAttachable(int $clientId, array $ids, ?int $exceptImportId): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $attachable = $this->attachableOrders($clientId, $exceptImportId)->keyBy('id');
        foreach ($ids as $id) {
            if ($attachable->has($id)) {
                continue;
            }
            $order = Order::query()->withoutGlobalScopes()->where('client_id', $clientId)->find($id, ['id', 'order_no']);
            if ($order === null) {
                throw new RuleViolation("Order {$id} is not an order of client {$clientId}.", 'orders.imports.errors.order_unknown', ['id' => $id]);
            }
            throw new RuleViolation("Order {$order->order_no} cannot be attached to a manual inbound list.", 'orders.imports.errors.order_not_attachable', ['order_no' => $order->order_no]);
        }

        return $ids;
    }

    /**
     * Inside confirm(): lock the ticked orders, re-check them (the client may have uploaded another list meanwhile, or customer
     * service may have generated the ASN) and return what `result.attached` records.
     *
     * @param  list<int|string>  $ids
     * @return list<array{order_id:int, order_no:string}>
     */
    private function lockAttached(int $clientId, array $ids, int $importId): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        Order::query()->withoutGlobalScopes()->whereKey($ids)->lockForUpdate()->get(['id']);
        $ids = $this->checkAttachable($clientId, $ids, $importId);

        return Order::query()->withoutGlobalScopes()->whereKey($ids)->orderBy('id')->get(['id', 'order_no'])
            ->map(fn (Order $order) => ['order_id' => (int) $order->id, 'order_no' => (string) $order->order_no])->all();
    }

    /**
     * Order ids already held by another portal submission of the client (see attachableOrders).
     *
     * @return array<int, true>
     */
    private function takenOrderIds(int $clientId, ?int $exceptImportId): array
    {
        $taken = [];
        OrderImport::query()->withoutGlobalScopes()->where('client_id', $clientId)->where('source', 'portal')
            ->whereIn('status', ['draft', 'pending', 'imported'])
            ->when($exceptImportId !== null, fn ($query) => $query->whereKeyNot($exceptImportId))
            ->get(['id', 'status', 'errors'])
            ->each(function (OrderImport $import) use (&$taken): void {
                $ids = $import->status === 'imported' ? $import->orderIds() : $import->manualAttachedIds();
                foreach ($ids as $id) {
                    $taken[$id] = true;
                }
            });

        return $taken;
    }

    /**
     * CHANGE_REQUESTS #125: the client's 需要我们上门提货 on a portal 入库清单 — the plan it ticked (customer snapshot, or null when none
     * could be priced) and the packages derived from the list rows — merged into `errors.context.inbound.collection` right before
     * confirm(). The pickup address / ready date / warehouse stay as uploaded; only `preference` and `packages` are written. Row-locked;
     * pending portal imports that carry a collection request only. Pass the returned model to confirm(): it writes its own audit copy.
     *
     * @param  array{preference?:?array<string, mixed>, packages?:list<array<string, mixed>>}  $collection
     */
    public function recordInboundCollection(OrderImport $import, array $collection): OrderImport
    {
        return DB::transaction(function () use ($import, $collection): OrderImport {
            $locked = OrderImport::query()->lockForUpdate()->findOrFail($import->id);
            abort_unless($locked->status === 'pending', 409, __('orders.imports.errors.already_processed'));
            $audit = $locked->errors ?? [];
            $current = $audit['context']['inbound']['collection'] ?? null;
            if ($locked->source !== 'portal' || ! is_array($current)) {
                throw new RuleViolation("Import {$locked->id} carries no collection request.", 'orders.imports.errors.no_collection_request', ['id' => $locked->id]);
            }
            foreach (['preference', 'packages'] as $key) {
                if (array_key_exists($key, $collection)) {
                    $current[$key] = $collection[$key];
                }
            }
            $audit['context']['inbound']['collection'] = $current;
            $locked->update(['errors' => $audit]);

            return $locked->fresh();
        });
    }

    /**
     * One group per 唛头 from the rows the parser read. CHANGE_REQUESTS #136 (audit PORTAL-05): a mark that also has rows the parser
     * REFUSED (`$errors`, each carrying its `consignment_mark`) is blocked with the row numbers — the confirmed order would otherwise
     * silently lack those goods lines, and the short ASN built from it surfaces as a receiving discrepancy weeks later.
     *
     * CHANGE_REQUESTS #143 `group_by = recipient`: one group per recipient (name + postcode + address, case / whitespace insensitive);
     * the group's 唛头 is the first row's waybill root and every row's description is prefixed with its own waybill. A refused row then
     * blocks the group whose rows share its waybill ROOT (the refused carton belongs to the same consignment even though the
     * recipient cells that would have placed it are the very cells that may be broken). `address_type_default` replaces the
     * address-book / FBA / business inference for rows without an explicit 地址类型 value.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  list<array<string,mixed>>  $errors
     * @param  array<string,mixed>  $context
     * @return list<array<string,mixed>>
     */
    private function groups(array $rows, array $errors, array $context, string $sha256): array
    {
        $groups = [];
        $jobId = filled($context['job_id'] ?? null) ? (int) $context['job_id'] : null;
        $byRecipient = ($context['group_by'] ?? 'mark') === 'recipient';
        $addressDefault = $context['address_type_default'] ?? 'auto';
        $refused = [];
        foreach ($errors as $error) {
            if (filled($error['consignment_mark'] ?? null) && (int) ($error['row'] ?? 0) > 0) {
                $mark = trim((string) $error['consignment_mark']);
                $refused[mb_strtolower($byRecipient ? $this->waybillRoot($mark) : $mark)][(int) $error['row']] = true;
            }
        }
        $grouped = $byRecipient
            ? collect($rows)->groupBy(fn ($row) => $this->recipientKey($row))
            : collect($rows)->groupBy(fn ($row) => mb_strtolower(trim((string) $row['consignment_mark'])));
        foreach ($grouped as $groupKey => $markRows) {
            $first = $markRows->first();
            if ($byRecipient) {
                $mark = $this->waybillRoot((string) $first['consignment_mark']);
                $roots = $markRows->map(fn ($row) => mb_strtolower($this->waybillRoot((string) $row['consignment_mark'])))->unique();
                $errorRows = $roots->flatMap(fn ($root) => array_keys($refused[$root] ?? []))->unique()->values()->all();
                $markRows = $markRows->map(fn ($row) => $this->withWaybillInDescription($row));
            } else {
                $mark = (string) $first['consignment_mark'];
                $errorRows = array_keys($refused[$groupKey] ?? []);
            }
            sort($errorRows);
            $signatures = $markRows->map(fn ($row) => $this->signature($row))->unique();
            $key = hash('sha256', $sha256.'|'.$mark.'|'.$this->signature($first).($byRecipient ? '|'.$groupKey : ''));
            $address = $this->matchingAddress((int) $context['client_id'], $first);
            $group = [
                'key' => $key,
                'status' => 'ready',
                'message' => null,
                'consignment_mark' => $mark,
                'external_ref' => collect($markRows)->pluck('external_ref')->filter()->first(),
                'fba_reference' => $first['fba_reference'],
                'deliver_to_name' => $first['deliver_to_name'],
                'deliver_to_phone' => $first['deliver_to_phone'],
                'deliver_to_address' => $first['deliver_to_address'],
                'deliver_to_suburb' => $first['deliver_to_suburb'],
                'deliver_to_state' => $first['deliver_to_state'],
                'deliver_to_postcode' => $first['deliver_to_postcode'],
                // CHANGE_REQUESTS #136: the list's 地址类型 column wins; without it the address book, then the FBA reference, then business —
                // unless the import chose a default for such rows (CHANGE_REQUESTS #143).
                'deliver_to_address_type' => $first['deliver_to_address_type']
                    ?? ($addressDefault !== 'auto' ? $addressDefault : ($address?->address_type ?? ($first['fba_reference'] ? 'fba' : 'business'))),
                'delivery_instructions' => $address?->default_instructions,
                'client_address_id' => $address?->id,
                'save_address_suggested' => $address === null,
                // A 要求送达日 / 服务等级 column on the sheet wins over the form / portal default (first row of the mark that carries one).
                'requested_date' => collect($markRows)->pluck('requested_date')->filter()->first(),
                'service_level' => collect($markRows)->pluck('service_level')->filter()->first(),
                'row_numbers' => $markRows->pluck('row')->map(fn ($row) => (int) $row)->values()->all(),
                'error_rows' => $errorRows, // CHANGE_REQUESTS #136: rows of this mark the parser refused (blocks the group)
                'rows' => $markRows->values()->all(),
            ];
            $requestedDate = (string) ($group['requested_date'] ?? $context['requested_date']);

            if ($errorRows !== []) {
                $group['status'] = 'blocked';
                $group['message'] = __('orders.imports.errors.rows_not_read', ['mark' => $mark, 'rows' => implode('、', $errorRows)]);
            } elseif ($signatures->count() > 1) {
                $group['status'] = 'blocked';
                $group['message'] = __('orders.imports.errors.inconsistent_group', ['mark' => $mark]);
            } elseif ($jobId !== null && $this->matchingAsnExists((int) $context['client_id'], $jobId, $mark)) {
                $group['status'] = 'asn_match';
                $group['message'] = __('orders.imports.errors.matching_asn', ['mark' => $mark]);
            } elseif ($this->duplicateOrder((int) $context['client_id'], $group, $requestedDate)) {
                $group['status'] = 'duplicate';
                $group['message'] = __('orders.imports.errors.duplicate_order', ['mark' => $mark]);
            }

            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * CHANGE_REQUESTS #143: the recipient a row belongs to when grouping by recipient — name + postcode + address, lower-cased with
     * whitespace removed from the address, so "1 Sample St" and "1 sample st " are the same consignee.
     *
     * @param  array<string,mixed>  $row
     */
    private function recipientKey(array $row): string
    {
        return mb_strtolower(trim((string) ($row['deliver_to_name'] ?? '')).'|'.trim((string) ($row['deliver_to_postcode'] ?? '')).'|'.preg_replace('/\s+/u', '', (string) ($row['deliver_to_address'] ?? '')));
    }

    /** CHANGE_REQUESTS #143: "CW1001-2" → "CW1001" — the consolidation list numbers the cartons of one consignment with a trailing "-<n>". */
    private function waybillRoot(string $mark): string
    {
        $mark = trim($mark);
        $root = preg_replace('/-\d+$/', '', $mark);

        return filled($root) ? $root : $mark;
    }

    /**
     * CHANGE_REQUESTS #143: under recipient grouping every goods line keeps its own waybill in front of its description
     * ("<waybill> · <commodity>", the first filled description, 中文 first) so unit labels, the pick list and the order page show
     * which carton is which; a row without any description gets the waybill alone.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function withWaybillInDescription(array $row): array
    {
        $waybill = trim((string) $row['consignment_mark']);
        foreach (['description_cn', 'description_en'] as $field) {
            if (filled($row[$field] ?? null)) {
                if (! str_starts_with((string) $row[$field], $waybill.' · ')) {
                    $row[$field] = mb_substr($waybill.' · '.$row[$field], 0, 255);
                }

                return $row;
            }
        }
        $row['description_cn'] = mb_substr($waybill, 0, 255);

        return $row;
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
            $row['deliver_to_postcode'], $row['deliver_to_address_type'] ?? null, $row['fba_reference'], // #136: address type is part of the consignee
        ])));
    }

    private function addressKey(?string $address, ?string $state, ?string $postcode): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', (string) $address).'|'.trim((string) $state).'|'.trim((string) $postcode));
    }
}
