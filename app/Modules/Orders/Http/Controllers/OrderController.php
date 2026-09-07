<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\OrderEnums;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Platform\Models\Job;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class OrderController extends Controller
{
    public function index(Request $request): View
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
        ]);
    }

    public function store(Request $request, OrderCreationService $orders): RedirectResponse
    {
        $this->authorizeOrderEntry();
        $data = $this->validated($request);
        $order = $orders->createManual($data, $request->user()?->id);

        return redirect()->route('orders.show', $order)
            ->with('status', __('orders.messages.created', ['order_no' => $order->order_no]));
    }

    public function show(Order $order): View
    {
        return view('orders::show', [
            'order' => $order->load(['client', 'job', 'creator', 'lines', 'declaredPackages', 'events.actor']),
        ]);
    }

    /** A3 establishes the picking lock invariant; A11 later adds supervised overrides and cancellation. */
    public function update(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrderEntry();
        abort_unless($order->isEditable(), 403, __('orders.messages.locked'));

        $data = $request->validate([
            'deliver_to_name' => ['required', 'string', 'max:255'],
            'deliver_to_phone' => ['nullable', 'string', 'max:40'],
            'deliver_to_address' => ['required', 'string', 'max:255'],
            'deliver_to_suburb' => ['required', 'string', 'max:100'],
            'deliver_to_state' => ['required', Rule::in(Enums::STATES)],
            'deliver_to_postcode' => ['required', 'string', 'max:10'],
            'requested_date' => ['required', 'date'],
        ]);

        DB::transaction(fn () => $order->update($data));

        return redirect()->route('orders.show', $order)->with('status', __('orders.messages.updated'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('status', 'active')],
            'job_id' => ['required', 'integer', Rule::exists('jobs', 'id')],
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
            'requested_date' => ['required', 'date'],
            'service_level' => ['required', Rule::in(OrderEnums::SERVICE_LEVELS)],
            'pickup_name' => ['nullable', 'string', 'max:255'],
            'pickup_phone' => ['nullable', 'string', 'max:40'],
            'pickup_address_line' => ['nullable', 'string', 'max:255'],
            'pickup_suburb' => ['nullable', 'string', 'max:100'],
            'pickup_state' => ['nullable', Rule::in(Enums::STATES)],
            'pickup_postcode' => ['nullable', 'string', 'max:10'],
            'lines' => ['required', 'array', 'min:1'],
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
            'declared_packages' => ['nullable', 'array'],
            'declared_packages.*.package_type' => ['required_with:declared_packages.*.qty', 'nullable', 'string', 'max:30'],
            'declared_packages.*.qty' => ['required_with:declared_packages.*.package_type', 'nullable', 'integer', 'min:1'],
            'declared_packages.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'declared_packages.*.length_mm' => ['nullable', 'integer', 'min:0'],
            'declared_packages.*.width_mm' => ['nullable', 'integer', 'min:0'],
            'declared_packages.*.height_mm' => ['nullable', 'integer', 'min:0'],
        ]);

        $jobMatchesClient = Job::query()
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

    private function authorizeOrderEntry(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['admin', 'customer_service', 'dispatcher']), 403);
    }
}
