<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Http\OrderValidation;
use App\Modules\Orders\Services\OrderInboundService;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\Auth\RequiredRoles;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Contracts\View\View;
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

        return view('orders::inbound.index', [
            'groups' => $orders->groupBy('client_id'),
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
}
