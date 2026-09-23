<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Http\OrderValidation;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\Services\AutoImportService;
use App\Modules\Orders\Services\OrderInboundService;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\Auth\RequiredRoles;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** 从订单生成预报单 (CHANGE_REQUESTS #117): the worklist of orders whose goods have no ASN yet, and the one-click ASN for a picked set. */
final class OrderInboundController extends Controller
{
    public function index(Request $request, OrderInboundService $service): View
    {
        RequiredRoles::requireAny(OrderInboundService::ROLES);
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'order_ids' => ['nullable', 'array'],
            'order_ids.*' => ['integer'],
        ], OrderValidation::messages(), OrderValidation::attributes());

        $all = $service->candidates();
        $orders = filled($filters['client_id'] ?? null) ? $all->where('client_id', (int) $filters['client_id'])->values() : $all;
        $submissions = $this->portalSubmissions($orders);
        $importByOrder = []; // order id → portal import id (a plain loop: Collection::flatMap would renumber the integer keys)
        foreach ($submissions as $entries) {
            foreach ($entries as $submission) {
                foreach ($submission['order_ids'] as $orderId) {
                    $importByOrder[$orderId] = $submission['import']->id;
                }
            }
        }

        return view('orders::inbound.index', [
            'groups' => $orders->groupBy('client_id'),
            'submissions' => $submissions,
            'importByOrder' => $importByOrder,
            'filters' => $filters,
            'preselected' => array_map('intval', $filters['order_ids'] ?? []),
            'clients' => Client::query()->whereIn('id', $all->pluck('client_id')->unique())->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'inboundTypes' => Enums::INBOUND_TYPES,
            'containerSizes' => Enums::CONTAINER_SIZES,
            'unpackModes' => Enums::UNPACK_MODES,
        ]);
    }

    public function store(Request $request, OrderInboundService $service): RedirectResponse
    {
        RequiredRoles::requireAny(OrderInboundService::ROLES);
        // CHANGE_REQUESTS #125: the 到仓方式 fields only exist for 我方上门提货 — with 客户自送 they are dropped before any rule runs.
        $collect = fn (array $rules): array => ['exclude_unless:inbound_transport,we_collect', ...$rules];
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', Rule::exists('orders', 'id')],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('active', true)],
            'inbound_type' => ['required', Rule::in(Enums::INBOUND_TYPES)],
            'container_no' => ['nullable', 'string', 'max:20', 'required_if:inbound_type,container'],
            'container_size' => ['nullable', Rule::in(Enums::CONTAINER_SIZES), 'required_if:inbound_type,container'],
            'unpack_mode' => ['nullable', Rule::in(Enums::UNPACK_MODES)],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'inbound_transport' => ['nullable', Rule::in(Enums::ASN_INBOUND_TRANSPORTS)],
            'collection' => $collect(['required_if:inbound_transport,we_collect', 'array']),
            'collection.name' => $collect(['required_if:inbound_transport,we_collect', 'string', 'max:255']),
            'collection.phone' => $collect(['nullable', 'string', 'max:40']),
            'collection.address' => $collect(['required_if:inbound_transport,we_collect', 'string', 'max:255']),
            'collection.suburb' => $collect(['required_if:inbound_transport,we_collect', 'string', 'max:100']),
            'collection.state' => $collect(['required_if:inbound_transport,we_collect', Rule::in(Enums::STATES)]),
            'collection.postcode' => $collect(['required_if:inbound_transport,we_collect', 'regex:/^\d{4}$/']),
            'collection.type' => $collect(['nullable', Rule::in(Enums::ADDRESS_TYPES)]),
            // No "not before today" rule here on purpose: Warehouse refuses a past ready date (RuleViolation) and the whole generation rolls back.
            'collection_ready_date' => $collect(['required_if:inbound_transport,we_collect', 'date']),
            'collection_notes' => $collect(['nullable', 'string', 'max:2000']),
            'collection_import_id' => $collect(['nullable', 'integer']),
        ], OrderValidation::messages(), OrderValidation::attributes());

        try {
            $result = $service->generate(array_map('intval', $data['order_ids']), $data, $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['inbound' => RuleViolation::display($e)]);
        }

        $merged = $result['merged'] === [] ? '' : ' '.__('orders.inbound.merged_note', [
            'job_no' => $result['job_no'], 'orders' => implode(', ', $result['merged']), 'jobs' => $result['cancelled'] === [] ? '—' : implode(', ', $result['cancelled']),
        ]);

        return redirect()->route('warehouse.asns.show', $result['asn_id'])
            ->with('status', __('orders.inbound.messages.generated', ['asn_no' => $result['asn_no'], 'orders' => $result['orders'], 'lines' => $result['lines']]).$merged);
    }

    /**
     * CHANGE_REQUESTS #123: the 柜号 / 柜型 / 预计到港 / 参考号 / 备注 a client submitted with its portal 入库清单, for the candidate orders
     * that came out of those uploads — one entry per submission that still has an order waiting here, keyed by client.
     * CHANGE_REQUESTS #125: `collection` = the client's 需要我们上门提货 request on that list (warehouse_id, address, ready_date, notes,
     * preference — the plan it ticked with the client price; staff may see it), null when the client delivers itself.
     * CHANGE_REQUESTS #128: a manual list (`manual`, no file) counts its created AND attached orders (OrderImport::orderIds) — one card,
     * 选中并填入 ticks all of them; `attached_nos` names the attached ones (以订单为准: their data is the order's own).
     *
     * @param  Collection<int, Order>  $orders
     * @return array<int, list<array{import:OrderImport, inbound:array<string, mixed>, collection:?array<string, mixed>, file:?string, manual:bool, order_ids:list<int>, order_nos:list<string>, attached_nos:list<string>}>>
     */
    private function portalSubmissions(Collection $orders): array
    {
        if ($orders->isEmpty()) {
            return [];
        }
        $byId = $orders->keyBy('id');
        $result = [];
        OrderImport::query()->whereIn('source', AutoImportService::CLIENT_SOURCES)->where('status', 'imported') // #145: API / inbox lists group like a portal upload
            ->whereIn('client_id', $orders->pluck('client_id')->unique())->latest('id')->limit(300)->get()
            ->each(function (OrderImport $import) use ($byId, &$result): void {
                $ids = array_values(array_filter($import->orderIds(), fn (int $id) => $byId->has($id)));
                if ($ids === []) {
                    return;
                }
                $attached = array_values(array_filter(array_map('intval', array_column($import->errors['result']['attached'] ?? [], 'order_id')), fn (int $id) => $byId->has($id)));
                $inbound = is_array($import->errors['context']['inbound'] ?? null) ? $import->errors['context']['inbound'] : [];
                $result[(int) $import->client_id][] = [
                    'import' => $import,
                    'inbound' => $inbound,
                    'collection' => is_array($inbound['collection'] ?? null) ? $inbound['collection'] : null,
                    'file' => $import->errors['context']['original_name'] ?? null,
                    'manual' => $import->isManual(),
                    'order_ids' => $ids,
                    'order_nos' => array_map(fn (int $id) => (string) $byId[$id]->order_no, $ids),
                    'attached_nos' => array_map(fn (int $id) => (string) $byId[$id]->order_no, $attached),
                ];
            });

        return $result;
    }
}
