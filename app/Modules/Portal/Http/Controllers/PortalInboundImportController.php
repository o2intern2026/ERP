<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\OrderEnums;
use App\Modules\Orders\Services\OrderImportService;
use App\Modules\Orders\Services\SpreadsheetManifestParser;
use App\Modules\Platform\Models\Document;
use App\Modules\Portal\Http\PortalValidation;
use App\Modules\Portal\Services\PortalTransportEstimate;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\Contracts\ManifestParser;
use App\Support\Contracts\RateService;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * 客户门户 入库清单 CSV / Excel 提交 (CHANGE_REQUESTS #123). The client's list becomes the client's ORDERS (operational_status
 * received) through the same OrderImportService the staff 批量导入 uses — same parser, grouping, duplicate protection and confirm
 * path — with source `portal`, no Job chosen by the client (one loose Job opens with the first order at confirm) and the inbound
 * context (柜号 / 柜型 / 预计到港 / 参考号 / 备注) kept on the import for 待建预报, where customer service builds the ASN (CR #117 / #119).
 * Clients still never create ASNs. Isolation is the data-layer client scope on `order_imports` (BelongsToClient, applied before
 * route binding) plus an explicit owner check: another client's import is a 404.
 *
 * CHANGE_REQUESTS #125: the upload may say 需要我们上门提货 (pickup address / contact / ready date / warehouse, kept at
 * `inbound.collection`); the preview then lists the collection plans with CLIENT prices for the list's rows and the client ticks one
 * at confirm. Nothing reaches Transport here — customer service generates the ASN (and with it the collection) in 待建预报.
 *
 * CHANGE_REQUESTS #128 手工建立入库清单: the same submission typed on the page (rows through ManifestParser::fromRows — row errors
 * come back on the form in Chinese with the row and column) and / or the client's existing orders ticked to be attached. 以订单为准:
 * an attached order keeps its own consignee and goods — the preview shows them from the ORDER, read-only, and confirm records the
 * id only (`result.attached`); the list adds the inbound context and the collection request. 保存草稿 keeps everything for later.
 */
final class PortalInboundImportController extends Controller
{
    /** Header row of the CSV template — every entry is an alias SpreadsheetManifestParser::HEADERS accepts, in the client's reading order. */
    private const TEMPLATE_HEADERS = ['唛头', '中文品名', '英文品名', '包装类型', '箱数', '产品数量', '实重(KG)', '长(CM)', '宽(CM)', '高(CM)', '收件人', '电话', '地址', '城区', '州', '邮编', 'FBA参考号', '要求送达日', '存储等级'];

    public function index(Request $request): View
    {
        $clientId = $this->clientId($request);
        $imports = OrderImport::query()->where('client_id', $clientId)->where('source', 'portal')->latest('id')->paginate(20);
        $orderIds = $imports->getCollection()->flatMap(fn (OrderImport $import) => [...$import->orderIds(), ...$import->manualAttachedIds()])->unique()->values()->all();

        return view('portal::asns.imports.index', [
            'imports' => $imports,
            'orders' => Order::query()->whereKey($orderIds)->get(['id', 'order_no', 'operational_status'])->keyBy('id'),
            'asns' => $this->asnsByOrder($orderIds),
        ]);
    }

    public function create(Request $request): View
    {
        $clientId = $this->clientId($request);
        $warehouses = Warehouse::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name']);

        return view('portal::asns.imports.create', [
            'containerSizes' => Enums::CONTAINER_SIZES,
            'templateHeaders' => self::TEMPLATE_HEADERS,
            'warehouses' => $warehouses,
            'defaultWarehouseId' => $this->defaultWarehouseId($clientId, $warehouses),
            'defaults' => [],
        ]);
    }

    /** CSV skeleton: UTF-8 with BOM (Excel on Chinese Windows opens it correctly), the Chinese headers the parser accepts, two sample rows. */
    public function template(Request $request): Response
    {
        $this->clientId($request);
        $date = today()->addDays(14)->toDateString();
        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::TEMPLATE_HEADERS, ',', '"', '');
        fputcsv($out, ['EDW-001', '蓝牙音箱', 'Bluetooth speaker', '纸箱', '10', '200', '85', '60', '40', '40', 'Amazon FBA BWU2', '0400 000 000', '1 Warehouse Rd', 'Moorebank', 'NSW', '2170', 'FBA15ABC123', $date, '标准'], ',', '"', '');
        fputcsv($out, ['EDW-002', '电热水壶', 'Kettle', '纸箱', '5', '30', '32.5', '45', '35', '30', 'Shop B', '03 9999 0000', '12 High St', 'Richmond', 'VIC', '3121', '', $date, '底层'], ',', '"', '');
        rewind($out);
        $csv = "\xEF\xBB\xBF".stream_get_contents($out);
        fclose($out);

        return ResponseFacade::make($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="inbound-list-template.csv"',
        ]);
    }

    public function store(Request $request, OrderImportService $imports): RedirectResponse
    {
        $clientId = $this->clientId($request);
        $data = $request->validate([
            // .xls is accepted here so a real BIFF file gets the parser's Chinese "另存为 XLSX" message on the preview (a renamed CSV / XLSX just works).
            'manifest' => ['required', 'file', 'max:10240', function ($attribute, $value, $fail) {
                if (! in_array(mb_strtolower($value->getClientOriginalExtension()), ['csv', 'xlsx', 'xls', 'txt'], true)) {
                    $fail(__('portal.inbound.errors.unsupported_file'));
                }
            }],
            ...$this->inboundRules(),
            ...$this->collectionRules(false),
        ], PortalValidation::messages(), PortalValidation::attributes());

        $import = $imports->preview($data['manifest'], $this->submissionContext($clientId, $data), $request->user()->id);

        return redirect()->route('portal.asns.imports.show', $import)->with('status', __('portal.inbound.messages.uploaded'));
    }

    /** CHANGE_REQUESTS #128: the empty manual form, or `?draft={id}` to reopen the client's own draft (404 otherwise). */
    public function manualCreate(Request $request, OrderImportService $imports): View
    {
        $clientId = $this->clientId($request);
        $draft = $this->draft($clientId, $request->query('draft'));

        return $this->manualForm($clientId, $draft, $imports);
    }

    /** CHANGE_REQUESTS #128: continue a draft — the client's own, status draft, else 404. */
    public function manualEdit(Request $request, OrderImport $import, OrderImportService $imports): View
    {
        $import = $this->own($request, $import);
        abort_unless($import->status === 'draft', 404);

        return $this->manualForm((int) $import->client_id, $import, $imports);
    }

    /**
     * CHANGE_REQUESTS #128: `action = draft` keeps the typed rows / ticks / context as a draft (format rules only, nothing required);
     * `action = preview` needs at least one row or one ticked order, runs every row through ManifestParser::fromRows and sends the
     * row errors (Chinese, `第 N 行「列」…`, keyed `rows.{i}.{column}` so the cell is marked) back to the form with the input kept,
     * then hands the rows and the ticked ids to OrderImportService::previewRows → the same preview / confirm pages as the upload.
     * A ticked id that is not attachable (another client's, already in a submission, already on an ASN) is refused by the service
     * in Chinese and nothing is stored.
     */
    public function manualStore(Request $request, OrderImportService $imports, ManifestParser $parser): RedirectResponse
    {
        $clientId = $this->clientId($request);
        $draft = $this->draft($clientId, $request->input('draft_id'));
        $isDraft = $request->input('action') === 'draft';
        $formUrl = $draft === null ? route('portal.asns.imports.manual.create') : route('portal.asns.imports.manual.edit', $draft);

        $validator = Validator::make($request->all(), [
            'action' => ['nullable', Rule::in(['draft', 'preview'])],
            'draft_id' => ['nullable', 'integer'],
            'rows' => ['nullable', 'array', 'max:500'],
            'rows.*' => ['nullable', 'array'],
            'rows.*.*' => ['nullable', 'string', 'max:255'],
            'attached_order_ids' => ['nullable', 'array', 'max:200'],
            'attached_order_ids.*' => ['integer'],
            ...$this->inboundRules(),
            ...$this->collectionRules($isDraft),
        ], PortalValidation::messages(), PortalValidation::attributes());
        if ($validator->fails()) {
            throw (new ValidationException($validator))->redirectTo($formUrl);
        }
        $data = $validator->validated();

        $rows = $this->manualRows($data['rows'] ?? []);
        $attached = array_values(array_unique(array_map('intval', $data['attached_order_ids'] ?? [])));
        $context = $this->submissionContext($clientId, $data);
        $actorId = $request->user()->id;

        try {
            if ($isDraft) {
                $import = $imports->saveDraft($rows, $attached, $context, $actorId, $draft);

                return redirect()->route('portal.asns.imports.manual.edit', $import)->with('status', __('portal.inbound.manual.messages.draft_saved'));
            }

            $parsed = $parser->fromRows($rows);
            if ($parsed['errors'] !== []) {
                $messages = [];
                foreach ($parsed['errors'] as $error) {
                    $messages['rows.'.((int) $error['row'] - 1).'.'.$error['column']][] = $error['message'];
                }
                throw ValidationException::withMessages($messages)->redirectTo($formUrl);
            }
            if ($parsed['rows'] === [] && $attached === []) {
                throw ValidationException::withMessages(['rows' => __('portal.inbound.manual.errors.nothing')])->redirectTo($formUrl);
            }

            $import = $imports->previewRows($rows, $attached, $context, $actorId, $draft);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['attached_order_ids' => RuleViolation::display($e)])->redirectTo($formUrl);
        }

        return redirect()->route('portal.asns.imports.show', $import)->with('status', __('portal.inbound.messages.uploaded'));
    }

    public function show(Request $request, OrderImport $import, PortalTransportEstimate $estimate): View|RedirectResponse
    {
        $import = $this->own($request, $import);
        if ($import->status === 'draft') {
            return redirect()->route('portal.asns.imports.manual.edit', $import); // a draft is edited, not previewed (CHANGE_REQUESTS #128)
        }
        $audit = $import->errors ?? [];
        $groups = collect($audit['groups'] ?? []);
        $attachedOrders = $this->attachedOrders($import);
        $orderIds = array_values(array_unique([...$import->orderIds(), ...$attachedOrders->pluck('id')->map(fn ($id) => (int) $id)->all()]));
        $inbound = is_array($audit['context']['inbound'] ?? null) ? $audit['context']['inbound'] : [];
        $collection = is_array($inbound['collection'] ?? null) ? $inbound['collection'] : null;
        // CHANGE_REQUESTS #125: live plans while the list is pending; after confirm the page shows what was stored.
        $collectionEstimate = $collection !== null && $import->status === 'pending'
            ? $estimate->collectionOptions((int) $import->client_id, $collection, $this->estimateRows($groups, $attachedOrders))
            : null;
        $packages = $collectionEstimate['packages'] ?? (is_array($collection['packages'] ?? null) ? $collection['packages'] : []);

        return view('portal::asns.imports.show', [
            'import' => $import,
            'audit' => $audit,
            'inbound' => $inbound,
            'manual' => $import->isManual(),
            'attachedOrders' => $attachedOrders,
            'requestedDate' => $audit['context']['requested_date'] ?? null,
            'groups' => $groups,
            'readyCount' => $groups->where('status', 'ready')->count(),
            'blockedCount' => $groups->whereIn('status', ['blocked', 'duplicate', 'asn_match'])->count(),
            'errorRows' => count(array_unique(array_column($audit['issues'] ?? [], 'row'))),
            'orders' => Order::query()->whereKey($orderIds)->get(['id', 'order_no', 'operational_status'])->keyBy('id'),
            'asns' => $this->asnsByOrder($orderIds),
            'document' => $import->document_id ? Document::query()->find($import->document_id) : null, // client-scoped; client_visible for the client's own upload
            'collection' => $collection,
            'collectionEstimate' => $collectionEstimate,
            'collectionPackages' => $packages,
            'collectionUnpriced' => $collectionEstimate['unpriced_rows'] ?? $this->unpricedRows($packages),
            'collectionWarehouse' => $collection === null ? null : Warehouse::query()->find((int) ($collection['warehouse_id'] ?? 0), ['id', 'code', 'name']),
            'tierSurcharge' => $this->tierSurcharge((int) $import->client_id), // CHANGE_REQUESTS #126
        ]);
    }

    /**
     * All groups the preview marked ready; the client never saves addresses to the address book from a list. CHANGE_REQUESTS #125: a
     * collection request with priced plans needs `collection_choice` — the plans are recomputed here and only the key is trusted; the
     * chosen snapshot (customer fields) and the derived packages are recorded on the import before the orders are created.
     * CHANGE_REQUESTS #128: a manual list may carry attached orders and no typed row at all; their lines are part of the collection
     * packages, and the service re-checks them under a row lock — a refusal comes back in Chinese with nothing created.
     */
    public function confirm(Request $request, OrderImport $import, OrderImportService $imports, PortalTransportEstimate $estimate): RedirectResponse
    {
        $import = $this->own($request, $import);
        if ($import->status !== 'pending') {
            return redirect()->route('portal.asns.imports.show', $import)->with('status', __('portal.inbound.not_pending'));
        }
        $groups = collect($import->errors['groups'] ?? []);
        $keys = $groups->where('status', 'ready')->pluck('key')->all();
        $attachedOrders = $this->attachedOrders($import);
        if ($keys === [] && $attachedOrders->isEmpty()) {
            return redirect()->route('portal.asns.imports.show', $import)->with('status', __('portal.inbound.messages.nothing'));
        }

        $collection = $import->errors['context']['inbound']['collection'] ?? null;
        if (is_array($collection)) {
            $plans = $estimate->collectionOptions((int) $import->client_id, $collection, $this->estimateRows($groups, $attachedOrders));
            // Review UX-1: without one row carrying cartons + weight + all three dimensions there is nothing to collect by — Warehouse
            // refuses the collection when customer service generates the ASN (no_packages) and 待建预报 has no package fields. Refused here,
            // before any order exists: the client re-uploads with 重量 / 长宽高 (or chooses to deliver itself).
            if ($plans['reason'] === 'no_items') {
                return redirect()->route('portal.asns.imports.show', $import)->withErrors(['collection_choice' => __('portal.inbound.collection.errors.no_items')]);
            }
            $preference = null;
            if ($plans['options'] !== []) {
                $key = $request->input('collection_choice');
                if (! is_string($key) || trim($key) === '') {
                    return redirect()->route('portal.asns.imports.show', $import)->withErrors(['collection_choice' => __('portal.inbound.collection.errors.choice_required')]);
                }
                $option = collect($plans['options'])->firstWhere('key', $key);
                if ($option === null) {
                    return redirect()->route('portal.asns.imports.show', $import)->withErrors(['collection_choice' => __('portal.inbound.collection.errors.choice_changed')]);
                }
                $preference = PortalTransportEstimate::snapshot($option, $request->user()->id);
            }
            // confirm() writes its own copy of the audit: hand it the import as recorded here.
            $import = $imports->recordInboundCollection($import, ['preference' => $preference, 'packages' => $plans['packages']]);
        }

        try {
            $import = $imports->confirm($import, $keys, [], $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('portal.asns.imports.show', $import)->withErrors(['attached_order_ids' => RuleViolation::display($e)]);
        }

        $created = count($import->errors['result']['created'] ?? []);
        $attached = count($import->errors['result']['attached'] ?? []);

        return redirect()->route('portal.asns.imports.show', $import)->with('status', $import->isManual()
            ? __('portal.inbound.manual.messages.confirmed', ['count' => $created, 'attached' => $attached])
            : __('portal.inbound.messages.confirmed', ['count' => $created]));
    }

    /**
     * The manual form (CHANGE_REQUESTS #128): rows from old() else the draft, the attachable orders, ticks and the inbound / collection
     * defaults from the draft. old() always wins so a refused submission comes back exactly as typed.
     */
    private function manualForm(int $clientId, ?OrderImport $draft, OrderImportService $imports): View
    {
        $manual = is_array($draft?->errors['context']['manual'] ?? null) ? $draft->errors['context']['manual'] : [];
        $rows = array_values(array_filter((array) old('rows', $manual['rows'] ?? []), 'is_array')) ?: [[]];
        $warehouses = Warehouse::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name']);

        return view('portal::asns.imports.manual', [
            'draft' => $draft,
            'rows' => $rows,
            'attachable' => $imports->attachableOrders($clientId, $draft?->id),
            'attachedIds' => array_map('intval', (array) old('attached_order_ids', $manual['attached_order_ids'] ?? [])),
            'defaults' => $this->manualDefaults($draft),
            'containerSizes' => Enums::CONTAINER_SIZES,
            'packageTypes' => OrderEnums::PACKAGE_TYPES,
            'states' => Enums::STATES,
            'storageTiers' => Enums::STORAGE_TIERS,
            'warehouses' => $warehouses,
            'defaultWarehouseId' => $this->defaultWarehouseId($clientId, $warehouses),
        ]);
    }

    /**
     * What a draft filled in, in the form's own field names (the collection partial reads `old(name, $defaults[name])`).
     *
     * @return array<string, mixed>
     */
    private function manualDefaults(?OrderImport $draft): array
    {
        $inbound = is_array($draft?->errors['context']['inbound'] ?? null) ? $draft->errors['context']['inbound'] : [];
        $collection = is_array($inbound['collection'] ?? null) ? $inbound['collection'] : null;

        return [
            'container_no' => $inbound['container_no'] ?? null,
            'container_size' => $inbound['container_size'] ?? null,
            'expected_date' => $inbound['expected_date'] ?? null,
            'reference' => $inbound['reference'] ?? null,
            'notes' => $inbound['notes'] ?? null,
            'inbound_transport' => $collection === null ? 'client_delivers' : 'we_collect',
            'warehouse_id' => $collection['warehouse_id'] ?? null,
            'collection' => is_array($collection['address'] ?? null) ? $collection['address'] : [],
            'collection_ready_date' => $collection['ready_date'] ?? null,
            'collection_notes' => $collection['notes'] ?? null,
        ];
    }

    /** The client's own draft by id (query `?draft=` or the form's `draft_id`); null without an id, 404 for anything else. */
    private function draft(int $clientId, mixed $id): ?OrderImport
    {
        if (! filled($id)) {
            return null;
        }
        $draft = is_numeric($id) ? OrderImport::query()->where('client_id', $clientId)->where('source', 'portal')->find((int) $id) : null;
        abort_unless($draft !== null && $draft->status === 'draft', 404);

        return $draft;
    }

    /**
     * The posted rows in the parser's canonical shape, in posted order (blank rows kept in place so the row numbers on the form and
     * in the messages agree); unknown keys dropped, values trimmed strings.
     *
     * @param  array<int|string, mixed>  $rows
     * @return list<array<string, string>>
     */
    private function manualRows(array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $row) {
            $clean = [];
            foreach (SpreadsheetManifestParser::FORM_FIELDS as $field) {
                $value = is_array($row) ? ($row[$field] ?? null) : null;
                $clean[$field] = is_scalar($value) ? trim((string) $value) : '';
            }
            $out[] = $clean;
        }

        return $out;
    }

    /** The inbound context fields (柜号 / 柜型 / 预计到港日 / 参考号 / 备注) shared by the upload and the manual form. */
    private function inboundRules(): array
    {
        return [
            'container_no' => ['nullable', 'string', 'max:20'],
            'container_size' => ['nullable', Rule::in(Enums::CONTAINER_SIZES)],
            'expected_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * CHANGE_REQUESTS #125: the pickup fields exist only for 需要我们上门提货 — with 我们自己送到仓库 they are dropped before any rule runs.
     * A draft (CHANGE_REQUESTS #128) keeps the format rules but requires nothing, so a half-filled request can be saved and finished later.
     */
    private function collectionRules(bool $draft): array
    {
        $collect = fn (array $rules): array => ['exclude_unless:inbound_transport,we_collect', $draft ? 'nullable' : 'required_if:inbound_transport,we_collect', ...$rules];

        return [
            'inbound_transport' => ['nullable', Rule::in(Enums::ASN_INBOUND_TRANSPORTS)],
            'warehouse_id' => $collect(['integer', Rule::exists('warehouses', 'id')->where('active', true)]),
            'collection' => $collect(['array']),
            'collection.name' => $collect(['string', 'max:255']),
            'collection.phone' => $collect(['string', 'max:40']),
            'collection.address' => $collect(['string', 'max:255']),
            'collection.suburb' => $collect(['string', 'max:100']),
            'collection.state' => $collect([Rule::in(Enums::STATES)]),
            'collection.postcode' => $collect(['regex:/^\d{4}$/']),
            'collection.type' => $collect([Rule::in(Enums::ADDRESS_TYPES)]),
            'collection_ready_date' => $collect($draft ? ['date'] : ['date', 'after_or_equal:today']),
            'collection_notes' => ['exclude_unless:inbound_transport,we_collect', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The OrderImportService context of a portal submission: the signed-in client (never from the request), no Job, 要求送达日 default
     * = 预计到港日 + 7, service level standard, and the inbound context (+ the collection request when 需要我们上门提货).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function submissionContext(int $clientId, array $data): array
    {
        $expected = filled($data['expected_date'] ?? null) ? Carbon::parse($data['expected_date']) : today();
        $inbound = [
            'container_no' => filled($data['container_no'] ?? null) ? mb_strtoupper(trim((string) $data['container_no'])) : null,
            'container_size' => filled($data['container_size'] ?? null) ? (string) $data['container_size'] : null,
            'expected_date' => filled($data['expected_date'] ?? null) ? $expected->toDateString() : null,
            'reference' => filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null,
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
            'uploaded_at' => now()->toDateTimeString(),
        ];
        if (($data['inbound_transport'] ?? null) === 'we_collect') {
            $inbound['collection'] = $this->collectionContext($data);
        }

        return [
            'client_id' => $clientId, // never from the request: the signed-in client is the only possible owner
            'job_id' => null,
            'requested_date' => $expected->copy()->addDays(7)->toDateString(), // default; a 要求送达日 column / cell wins per mark
            'service_level' => 'standard',
            'source' => 'portal',
            'client_visible' => true,
            'inbound' => $inbound,
        ];
    }

    /**
     * The stored 需要我们上门提货 request (#125): warehouse, the pickup party in the same shape Warehouse stores on the ASN, ready date, notes.
     * Null-safe so a draft (#128) may hold a half-filled request.
     *
     * @param  array<string, mixed>  $data
     * @return array{warehouse_id:?int, address:array<string, string>, ready_date:?string, notes:?string}
     */
    private function collectionContext(array $data): array
    {
        $address = is_array($data['collection'] ?? null) ? $data['collection'] : [];
        $field = fn (string $key): string => trim((string) ($address[$key] ?? ''));

        return [
            'warehouse_id' => filled($data['warehouse_id'] ?? null) ? (int) $data['warehouse_id'] : null,
            'address' => [
                'name' => $field('name'),
                'phone' => $field('phone'),
                'address' => $field('address'),
                'suburb' => $field('suburb'),
                'state' => mb_strtoupper($field('state')),
                'postcode' => $field('postcode'),
                'type' => $field('type') ?: 'business',
            ],
            'ready_date' => filled($data['collection_ready_date'] ?? null) ? Carbon::parse($data['collection_ready_date'])->toDateString() : null,
            'notes' => filled($data['collection_notes'] ?? null) ? trim((string) $data['collection_notes']) : null,
        ];
    }

    /**
     * The client's orders a manual list attached (CHANGE_REQUESTS #128), read from the ORDER with their goods lines: the posted ticks
     * while pending, `result.attached` once confirmed. Client-scoped, so another client's id can never surface here.
     *
     * @return EloquentCollection<int, Order>
     */
    private function attachedOrders(OrderImport $import): EloquentCollection
    {
        $ids = in_array($import->status, ['pending', 'draft'], true)
            ? $import->manualAttachedIds()
            : array_values(array_unique(array_map('intval', array_column($import->errors['result']['attached'] ?? [], 'order_id'))));

        return $ids === [] ? new EloquentCollection : Order::query()->with('lines')->whereKey($ids)->orderBy('id')->get();
    }

    /**
     * What the collection estimate prices (#125): the ready groups' rows plus, for a manual list (#128), the attached orders' goods lines
     * that still wait for an ASN, shaped like import rows (cartons, line weight, dims, package, description; `label` = the order no) —
     * exactly the ASN goods lines customer service will generate, so the estimate equals what Transport prices later.
     *
     * @param  Collection<int, array<string, mixed>>  $groups
     * @param  EloquentCollection<int, Order>  $attachedOrders
     * @return list<array<string, mixed>>
     */
    private function estimateRows(Collection $groups, EloquentCollection $attachedOrders): array
    {
        $rows = $groups->where('status', 'ready')->flatMap(fn (array $group) => $group['rows'] ?? [])->values()->all();
        foreach ($attachedOrders as $order) {
            foreach ($order->lines->whereNull('asn_line_id') as $line) {
                $rows[] = [
                    'row' => 0,
                    'label' => (string) $order->order_no,
                    'carton_qty' => (int) $line->carton_qty,
                    'actual_weight_kg' => $line->actual_weight_kg,
                    'length_mm' => $line->length_mm,
                    'width_mm' => $line->width_mm,
                    'height_mm' => $line->height_mm,
                    'package_type' => $line->package_type,
                    'description_cn' => $line->description_cn,
                    'description_en' => $line->description_en,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     * @return list<int|string>
     */
    private function unpricedRows(array $packages): array
    {
        return array_values(array_map(fn (array $p) => $p['label'] ?? (int) ($p['row'] ?? 0), array_filter($packages, fn (array $p) => ($p['weight_kg'] ?? null) === null
            || (int) ($p['length_mm'] ?? 0) <= 0 || (int) ($p['width_mm'] ?? 0) <= 0 || (int) ($p['height_mm'] ?? 0) <= 0)));
    }

    /** The bound import must be the signed-in client's own portal submission (the global scope already hides other clients' rows). */
    private function own(Request $request, OrderImport $import): OrderImport
    {
        $clientId = $this->clientId($request);
        abort_unless((int) $import->client_id === $clientId && $import->source === 'portal', 404);

        return $import;
    }

    /** @param EloquentCollection<int, Warehouse> $warehouses */
    private function defaultWarehouseId(int $clientId, EloquentCollection $warehouses): ?int
    {
        $default = PortalTransportEstimate::defaultWarehouse($clientId);

        return $default !== null && $warehouses->contains('id', (int) $default->id) ? (int) $default->id : $warehouses->first()?->id;
    }

    /**
     * The ASNs staff have since built for these orders (待建预报 → 从订单生成预报单), read through the client-scoped Asn model.
     *
     * @param  list<int>  $orderIds
     * @return array<int, array<int, string>> order id → [asn id → asn no]
     */
    private function asnsByOrder(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $orderByLine = OrderLine::query()->whereIn('order_id', $orderIds)->whereNotNull('asn_line_id')->pluck('order_id', 'id');
        if ($orderByLine->isEmpty()) {
            return [];
        }

        $map = [];
        Asn::query()->whereHas('lines', fn ($query) => $query->whereIn('order_line_id', $orderByLine->keys()))
            ->with(['lines' => fn ($query) => $query->whereIn('order_line_id', $orderByLine->keys())])
            ->get(['id', 'asn_no'])
            ->each(function (Asn $asn) use (&$map, $orderByLine): void {
                foreach ($asn->lines as $line) {
                    $map[(int) $orderByLine[$line->order_line_id]][(int) $asn->id] = (string) $asn->asn_no;
                }
            });

        return $map;
    }

    /** Portal uploads belong to the signed-in client user; staff use /orders/imports. */
    private function clientId(Request $request): int
    {
        $user = $request->user();
        abort_unless($user?->isClientUser() && $user->client_id !== null, 403, __('portal.messages.client_only'));

        return (int) $user->client_id;
    }

    /**
     * CHANGE_REQUESTS #126: the client's OWN bottom-level surcharge percent per warehouse, read through RateService (a price, never cost).
     * Empty when no card of the client prices WH-STORAGE-TIER-PLT-WK as a percent.
     *
     * @return array<string, string> warehouse code → "10%"
     */
    private function tierSurcharge(int $clientId): array
    {
        $rates = app(RateService::class);
        $out = [];
        foreach (Warehouse::query()->where('active', true)->orderBy('code')->pluck('code', 'id') as $warehouseId => $code) {
            $priced = $rates->price($clientId, 'WH-STORAGE-TIER-PLT-WK', 1, ['storage_tier' => 'bottom', 'warehouse_id' => (int) $warehouseId, 'base_cents' => 1_000_000]);
            if (! $priced['missing_rate'] && ! $priced['is_poa'] && ! $priced['min_charge_applied'] && ($priced['calculation_snapshot']['pricing_mode'] ?? null) === 'percent') {
                $out[(string) $code] = rtrim(rtrim(number_format($priced['amount_cents'] / 10_000, 2, '.', ''), '0'), '.').'%';
            }
        }

        return $out;
    }
}
