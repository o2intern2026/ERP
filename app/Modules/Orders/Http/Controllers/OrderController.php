<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Exceptions\OrderRuleViolation;
use App\Modules\Orders\Models\ClientAddress;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\OrderEnums;
use App\Modules\Orders\Services\FulfilmentService;
use App\Modules\Orders\Services\OrderBatchService;
use App\Modules\Orders\Services\OrderChangeService;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderHoldService;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Orders\Services\TailgateRule;
use App\Modules\Platform\Models\Job;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class OrderController extends Controller
{
    public function index(Request $request, OrderHoldService $holds): View
    {
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(OrderEnums::OPERATIONAL_STATUSES)],
            'requested_date' => ['nullable', 'date'],
            'consignment_mark' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', Rule::in(Enums::STATES)],
        ]);

        $orders = Order::query()->with(['client', 'job'])
            ->when($filters['client_id'] ?? null, fn ($query, $value) => $query->where('client_id', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('operational_status', $value))
            ->when($filters['requested_date'] ?? null, fn ($query, $value) => $query->whereDate('requested_date', $value))
            ->when($filters['consignment_mark'] ?? null, fn ($query, $value) => $query->where('consignment_mark', 'like', '%'.$value.'%'))
            ->when($filters['state'] ?? null, fn ($query, $value) => $query->where('deliver_to_state', $value))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('orders::index', [
            'orders' => $orders,
            'holdTypes' => $holds->activeTypesFor($orders->getCollection()), // A13: locked orders are highlighted in the list
            'filters' => $filters,
            'clients' => Client::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'statuses' => OrderEnums::OPERATIONAL_STATUSES,
            'states' => Enums::STATES,
        ]);
    }

    public function create(): View
    {
        $this->authorizeOrderEntry();

        return view('orders::form', [
            'clients' => Client::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            'jobs' => Job::query()->with('client')->where('operational_status', '!=', 'cancelled')->latest('id')->get(),
            'types' => OrderEnums::TYPES,
            'serviceLevels' => OrderEnums::SERVICE_LEVELS,
            'addressTypes' => OrderEnums::ADDRESS_TYPES,
            'states' => Enums::STATES,
            'addresses' => ClientAddress::query()
                ->orderByDesc('usage_count')
                ->orderByDesc('last_used_at')
                ->orderBy('label')
                ->get(),
        ]);
    }

    public function store(Request $request, OrderCreationService $orders): RedirectResponse
    {
        $this->authorizeOrderEntry();
        $this->mergeSavedAddress($request);
        $data = $this->validated($request);
        $order = $orders->createManual($data, $request->user()?->id);

        return redirect()->route('orders.show', $order)
            ->with('status', __('orders.messages.created', ['order_no' => $order->order_no]));
    }

    public function show(Order $order, FulfilmentService $fulfilments, OrderHoldService $holds, OrderBatchService $batches, TailgateRule $tailgate, OrderChangeService $changes): View
    {
        $order->load(['client', 'job', 'creator', 'lines.fulfilmentLines', 'declaredPackages', 'fulfilments.lines.orderLine', 'events.actor', 'originalOrder', 'returnOrders', 'returnDecider']);

        return view('orders::show', [
            'order' => $order,
            'availability' => $order->order_type === 'from_stock' ? $fulfilments->availability($order) : [],
            'holds' => $holds->activeFor($order),
            'holdTypes' => Enums::HOLD_TYPES,
            'asnRefs' => $batches->asnRefsFor($order),
            'tailgate' => $tailgate->evaluate($order),
            'canChange' => $changes->canChange($order, auth()->user()),        // A11: stage + role rule decided server side
            'requiresReason' => $changes->requiresReason($order),
            'canRequestReturn' => $order->acceptsReturnRequest() && auth()->user()->hasAnyRole(OrderChangeService::COORDINATOR_ROLES),
        ]);
    }

    /** A16: a person may override the automatic tailgate decision; the reason goes into the timeline (§3.8 #10). */
    public function tailgate(Request $request, Order $order, OrderStatusService $statuses): RedirectResponse
    {
        $this->authorizeOrderEntry();
        abort_if(in_array($order->operational_status, ['dispatched', 'delivered', 'returned', 'cancelled'], true), 403, __('orders.tailgate.messages.locked'));
        $data = $request->validate(['tailgate_required' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:255']]);

        DB::transaction(function () use ($order, $data, $request): void {
            $order->update(['tailgate_required' => (bool) $data['tailgate_required'], 'tailgate_reason' => 'manual']);
            OrderEvent::query()->create([
                'order_id' => $order->id, 'dimension' => 'operational', 'from_status' => $order->operational_status, 'to_status' => $order->operational_status,
                'actor_type' => 'user', 'actor_id' => $request->user()->id,
                'note' => __($data['tailgate_required'] ? 'orders.tailgate.timeline.forced' : 'orders.tailgate.timeline.cleared', ['reason' => $data['reason']]), 'created_at' => now(),
            ]);
        });

        return back()->with('status', __('orders.tailgate.messages.saved'));
    }

    public function confirm(Order $order, OrderStatusService $statuses): RedirectResponse
    {
        $this->authorizeOrderEntry();

        if ($order->operational_status !== 'received') {
            return back()->withErrors(['order' => __('orders.validation.confirm_received_only')]);
        }

        if ($order->order_type === 'from_stock' && $order->lines()->whereNull('asn_line_id')->exists()) {
            return back()->withErrors(['order' => __('orders.validation.unlinked_stock')]);
        }

        $statuses->transitionOperational($order, 'confirmed', auth()->id(), __('orders.fulfilments.timeline.confirmed'));

        return back()->with('status', __('orders.messages.confirmed'));
    }

    /** A3 picking lock + A11 stage rules: free before picking, supervisor + reason from picking on, never after dispatch. */
    public function update(Request $request, Order $order, OrderChangeService $changes): RedirectResponse
    {
        try {
            $changes->authorizeChange($order, $request->user());
        } catch (OrderRuleViolation $e) {
            abort(403, $e->getMessage());
        }

        $data = $request->validate([
            'deliver_to_name' => ['required', 'string', 'max:255'],
            'deliver_to_phone' => ['nullable', 'string', 'max:40'],
            'deliver_to_address' => ['required', 'string', 'max:255'],
            'deliver_to_suburb' => ['required', 'string', 'max:100'],
            'deliver_to_state' => ['required', Rule::in(Enums::STATES)],
            'deliver_to_postcode' => ['required', 'string', 'max:10'],
            'delivery_instructions' => ['nullable', 'string', 'max:2000'],
            'requested_date' => ['required', 'date'],
            'reason' => [$changes->requiresReason($order) ? 'required' : 'nullable', 'string', 'max:255'],
        ]);

        try {
            $changes->updateDelivery($order, Arr::except($data, ['reason']), $request->user(), $data['reason'] ?? null);
        } catch (OrderRuleViolation $e) {
            return back()->withErrors(['change' => $e->getMessage()]);
        }

        return redirect()->route('orders.show', $order)->with('status', __('orders.messages.updated'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('status', 'active')],
            'job_id' => ['nullable', 'integer', Rule::exists('jobs', 'id')],
            'order_type' => ['required', Rule::in(OrderEnums::TYPES)],
            'external_ref' => [
                'nullable', 'string', 'max:255',
                Rule::unique('orders', 'external_ref')->where(fn ($query) => $query->where('client_id', $request->integer('client_id'))),
            ],
            'consignment_mark' => ['nullable', 'string', 'max:255'],
            'fba_reference' => ['nullable', 'string', 'max:255'],
            'deliver_to_name' => ['required', 'string', 'max:255'],
            'deliver_to_phone' => ['nullable', 'string', 'max:40'],
            'deliver_to_address' => ['required', 'string', 'max:255'],
            'deliver_to_suburb' => ['required', 'string', 'max:100'],
            'deliver_to_state' => ['required', Rule::in(Enums::STATES)],
            'deliver_to_postcode' => ['required', 'string', 'max:10'],
            'deliver_to_address_type' => ['required', Rule::in(OrderEnums::ADDRESS_TYPES)],
            'delivery_instructions' => ['nullable', 'string', 'max:2000'],
            'client_address_id' => [
                'nullable', 'integer',
                Rule::exists('client_addresses', 'id')
                    ->where(fn ($query) => $query->where('client_id', $request->integer('client_id'))),
            ],
            'requested_date' => ['required', 'date'],
            'service_level' => ['required', Rule::in(OrderEnums::SERVICE_LEVELS)],
            'pickup_name' => ['nullable', 'string', 'max:255'],
            'pickup_phone' => ['nullable', 'string', 'max:40'],
            'pickup_address_line' => ['nullable', 'required_if:order_type,pickup_deliver', 'string', 'max:255'],
            'pickup_suburb' => ['nullable', 'required_if:order_type,pickup_deliver', 'string', 'max:100'],
            'pickup_state' => ['nullable', 'required_if:order_type,pickup_deliver', Rule::in(Enums::STATES)],
            'pickup_postcode' => ['nullable', 'required_if:order_type,pickup_deliver', 'string', 'max:10'],
            'lines' => ['nullable', 'required_unless:order_type,pickup_deliver', 'array'],
            'lines.*.description_cn' => ['nullable', 'string', 'max:255', 'required_without:lines.*.description_en'],
            'lines.*.description_en' => ['nullable', 'string', 'max:255', 'required_without:lines.*.description_cn'],
            'lines.*.package_type' => ['required', 'string', 'max:30'],
            'lines.*.carton_qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit_qty' => ['nullable', 'integer', 'min:0'],
            'lines.*.actual_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'lines.*.length_mm' => ['nullable', 'integer', 'min:0'],
            'lines.*.width_mm' => ['nullable', 'integer', 'min:0'],
            'lines.*.height_mm' => ['nullable', 'integer', 'min:0'],
            'lines.*.cbm' => ['nullable', 'numeric', 'min:0'],
            'declared_packages' => ['nullable', 'required_if:order_type,pickup_deliver', 'array'],
            'declared_packages.*.package_type' => ['required_with:declared_packages.*.qty', 'nullable', 'string', 'max:30'],
            'declared_packages.*.qty' => ['required_with:declared_packages.*.package_type', 'nullable', 'integer', 'min:1'],
            'declared_packages.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'declared_packages.*.length_mm' => ['nullable', 'integer', 'min:0'],
            'declared_packages.*.width_mm' => ['nullable', 'integer', 'min:0'],
            'declared_packages.*.height_mm' => ['nullable', 'integer', 'min:0'],
        ]);

        $jobMatchesClient = blank($data['job_id'] ?? null) || Job::query()
            ->whereKey($data['job_id'])
            ->where('client_id', $data['client_id'])
            ->exists();

        if (! $jobMatchesClient) {
            abort(422, __('orders.validation.job_client_mismatch'));
        }

        $pickupFields = collect(['name', 'phone', 'address', 'suburb', 'state', 'postcode'])
            ->mapWithKeys(fn ($field) => [$field => $data[$field === 'address' ? 'pickup_address_line' : 'pickup_'.$field] ?? null])
            ->all();
        $data['pickup_address'] = collect($pickupFields)->filter(fn ($value) => filled($value))->isEmpty() ? null : $pickupFields;

        return $data;
    }

    private function mergeSavedAddress(Request $request): void
    {
        if (! $request->filled('client_address_id') || ! $request->filled('client_id')) {
            return;
        }

        $address = ClientAddress::query()
            ->whereKey($request->integer('client_address_id'))
            ->where('client_id', $request->integer('client_id'))
            ->first();

        if (! $address) {
            return;
        }

        $snapshot = [
            'deliver_to_name' => $address->contact_name ?: $address->label,
            'deliver_to_phone' => $address->phone,
            'deliver_to_address' => $address->address,
            'deliver_to_suburb' => $address->suburb,
            'deliver_to_state' => $address->state,
            'deliver_to_postcode' => $address->postcode,
            'deliver_to_address_type' => $address->address_type,
            'delivery_instructions' => $address->default_instructions,
        ];

        $request->merge(collect($snapshot)
            ->reject(fn ($value, $field) => $request->filled($field))
            ->all());
    }

    private function authorizeOrderEntry(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['admin', 'customer_service', 'dispatcher']), 403);
    }
}
