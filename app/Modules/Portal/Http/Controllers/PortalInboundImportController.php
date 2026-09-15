<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Services\OrderImportService;
use App\Modules\Platform\Models\Document;
use App\Modules\Portal\Http\PortalValidation;
use App\Modules\Portal\Services\PortalTransportEstimate;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Illuminate\Validation\Rule;

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
 */
final class PortalInboundImportController extends Controller
{
    /** Header row of the CSV template — every entry is an alias SpreadsheetManifestParser::HEADERS accepts, in the client's reading order. */
    private const TEMPLATE_HEADERS = ['唛头', '中文品名', '英文品名', '包装类型', '箱数', '产品数量', '实重(KG)', '长(CM)', '宽(CM)', '高(CM)', '收件人', '电话', '地址', '城区', '州', '邮编', 'FBA参考号', '要求送达日'];

    public function index(Request $request): View
    {
        $clientId = $this->clientId($request);
        $imports = OrderImport::query()->where('client_id', $clientId)->where('source', 'portal')->latest('id')->paginate(20);
        $orderIds = $imports->getCollection()->flatMap(fn (OrderImport $import) => $this->createdOrderIds($import))->unique()->values()->all();

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
        $default = PortalTransportEstimate::defaultWarehouse($clientId);

        return view('portal::asns.imports.create', [
            'containerSizes' => Enums::CONTAINER_SIZES,
            'templateHeaders' => self::TEMPLATE_HEADERS,
            'warehouses' => $warehouses,
            'defaultWarehouseId' => $default !== null && $warehouses->contains('id', (int) $default->id) ? (int) $default->id : $warehouses->first()?->id,
        ]);
    }

    /** CSV skeleton: UTF-8 with BOM (Excel on Chinese Windows opens it correctly), the Chinese headers the parser accepts, two sample rows. */
    public function template(Request $request): Response
    {
        $this->clientId($request);
        $date = today()->addDays(14)->toDateString();
        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::TEMPLATE_HEADERS, ',', '"', '');
        fputcsv($out, ['EDW-001', '蓝牙音箱', 'Bluetooth speaker', '纸箱', '10', '200', '85', '60', '40', '40', 'Amazon FBA BWU2', '0400 000 000', '1 Warehouse Rd', 'Moorebank', 'NSW', '2170', 'FBA15ABC123', $date], ',', '"', '');
        fputcsv($out, ['EDW-002', '电热水壶', 'Kettle', '纸箱', '5', '30', '32.5', '45', '35', '30', 'Shop B', '03 9999 0000', '12 High St', 'Richmond', 'VIC', '3121', '', $date], ',', '"', '');
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
        // CHANGE_REQUESTS #125: the pickup fields exist only for 需要我们上门提货 — with 我们自己送到仓库 they are dropped before any rule runs.
        $collect = fn (array $rules): array => ['exclude_unless:inbound_transport,we_collect', 'required_if:inbound_transport,we_collect', ...$rules];
        $data = $request->validate([
            // .xls is accepted here so a real BIFF file gets the parser's Chinese "另存为 XLSX" message on the preview (a renamed CSV / XLSX just works).
            'manifest' => ['required', 'file', 'max:10240', function ($attribute, $value, $fail) {
                if (! in_array(mb_strtolower($value->getClientOriginalExtension()), ['csv', 'xlsx', 'xls', 'txt'], true)) {
                    $fail(__('portal.inbound.errors.unsupported_file'));
                }
            }],
            'container_no' => ['nullable', 'string', 'max:20'],
            'container_size' => ['nullable', Rule::in(Enums::CONTAINER_SIZES)],
            'expected_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
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
            'collection_ready_date' => $collect(['date', 'after_or_equal:today']),
            'collection_notes' => ['exclude_unless:inbound_transport,we_collect', 'nullable', 'string', 'max:2000'],
        ], PortalValidation::messages(), PortalValidation::attributes());

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
            $inbound['collection'] = $this->collectionRequest($data);
        }

        $import = $imports->preview($data['manifest'], [
            'client_id' => $clientId, // never from the request: the signed-in client is the only possible owner
            'job_id' => null,
            'requested_date' => $expected->copy()->addDays(7)->toDateString(), // default; a 要求送达日 column on the sheet wins per mark
            'service_level' => 'standard',
            'source' => 'portal',
            'client_visible' => true,
            'inbound' => $inbound,
        ], $request->user()->id);

        return redirect()->route('portal.asns.imports.show', $import)->with('status', __('portal.inbound.messages.uploaded'));
    }

    public function show(Request $request, OrderImport $import, PortalTransportEstimate $estimate): View
    {
        $import = $this->own($request, $import);
        $audit = $import->errors ?? [];
        $groups = collect($audit['groups'] ?? []);
        $orderIds = $this->createdOrderIds($import);
        $inbound = is_array($audit['context']['inbound'] ?? null) ? $audit['context']['inbound'] : [];
        $collection = is_array($inbound['collection'] ?? null) ? $inbound['collection'] : null;
        // CHANGE_REQUESTS #125: live plans while the list is pending; after confirm the page shows what was stored.
        $collectionEstimate = $collection !== null && $import->status === 'pending'
            ? $estimate->collectionOptions((int) $import->client_id, $collection, $this->readyRows($groups))
            : null;
        $packages = $collectionEstimate['packages'] ?? (is_array($collection['packages'] ?? null) ? $collection['packages'] : []);

        return view('portal::asns.imports.show', [
            'import' => $import,
            'audit' => $audit,
            'inbound' => $inbound,
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
        ]);
    }

    /**
     * All groups the preview marked ready; the client never saves addresses to the address book from a list. CHANGE_REQUESTS #125: a
     * collection request with priced plans needs `collection_choice` — the plans are recomputed here and only the key is trusted; the
     * chosen snapshot (customer fields) and the derived packages are recorded on the import before the orders are created.
     */
    public function confirm(Request $request, OrderImport $import, OrderImportService $imports, PortalTransportEstimate $estimate): RedirectResponse
    {
        $import = $this->own($request, $import);
        if ($import->status !== 'pending') {
            return redirect()->route('portal.asns.imports.show', $import)->with('status', __('portal.inbound.not_pending'));
        }
        $groups = collect($import->errors['groups'] ?? []);
        $keys = $groups->where('status', 'ready')->pluck('key')->all();
        if ($keys === []) {
            return redirect()->route('portal.asns.imports.show', $import)->with('status', __('portal.inbound.messages.nothing'));
        }

        $collection = $import->errors['context']['inbound']['collection'] ?? null;
        if (is_array($collection)) {
            $plans = $estimate->collectionOptions((int) $import->client_id, $collection, $this->readyRows($groups));
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

        $import = $imports->confirm($import, $keys, [], $request->user()->id);

        return redirect()->route('portal.asns.imports.show', $import)
            ->with('status', __('portal.inbound.messages.confirmed', ['count' => count($import->errors['result']['created'] ?? [])]));
    }

    /**
     * The stored 需要我们上门提货 request (#125): warehouse, the pickup party in the same shape Warehouse stores on the ASN, ready date, notes.
     *
     * @param  array<string, mixed>  $data
     * @return array{warehouse_id:int, address:array<string, string>, ready_date:string, notes:?string}
     */
    private function collectionRequest(array $data): array
    {
        $address = (array) $data['collection'];

        return [
            'warehouse_id' => (int) $data['warehouse_id'],
            'address' => [
                'name' => trim((string) $address['name']),
                'phone' => trim((string) $address['phone']),
                'address' => trim((string) $address['address']),
                'suburb' => trim((string) $address['suburb']),
                'state' => mb_strtoupper(trim((string) $address['state'])),
                'postcode' => trim((string) $address['postcode']),
                'type' => (string) $address['type'],
            ],
            'ready_date' => Carbon::parse($data['collection_ready_date'])->toDateString(),
            'notes' => filled($data['collection_notes'] ?? null) ? trim((string) $data['collection_notes']) : null,
        ];
    }

    /**
     * Every row of the groups the preview marked ready — the goods lines the orders (and later the ASN) will carry.
     *
     * @param  Collection<int, array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function readyRows(Collection $groups): array
    {
        return $groups->where('status', 'ready')->flatMap(fn (array $group) => $group['rows'] ?? [])->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     * @return list<int>
     */
    private function unpricedRows(array $packages): array
    {
        return array_values(array_map(fn (array $p) => (int) ($p['row'] ?? 0), array_filter($packages, fn (array $p) => ($p['weight_kg'] ?? null) === null
            || (int) ($p['length_mm'] ?? 0) <= 0 || (int) ($p['width_mm'] ?? 0) <= 0 || (int) ($p['height_mm'] ?? 0) <= 0)));
    }

    /** The bound import must be the signed-in client's own portal submission (the global scope already hides other clients' rows). */
    private function own(Request $request, OrderImport $import): OrderImport
    {
        $clientId = $this->clientId($request);
        abort_unless((int) $import->client_id === $clientId && $import->source === 'portal', 404);

        return $import;
    }

    /** @return list<int> */
    private function createdOrderIds(OrderImport $import): array
    {
        return array_values(array_map('intval', array_column($import->errors['result']['created'] ?? [], 'order_id')));
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
}
