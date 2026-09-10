<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Http\OrderFormRows;
use App\Modules\Orders\Models\ClientAddress;
use App\Modules\Orders\Models\DeclaredPackage;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\OrderEnums;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderEstimateService;
use App\Modules\Orders\Services\TailgateRule;
use App\Modules\Portal\Services\PortalTransportQuotes;
use App\Modules\Transport\Models\Shipment;
use App\Support\Contracts\RateService;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A9-p / OMS-10 + PLT-3: the client's own orders. Isolation is the data-layer client scope (ClientScope + BelongsToClient),
 * so every query here is naturally limited to the signed-in client; another client's order is a 404, never a 403.
 * Cost, margin and internal timeline notes are never loaded here; the customer quote is shown in customer prices only (A7b).
 */
final class PortalOrderController extends Controller
{
    /** Customer-facing statuses (enums.md §3) → internal operational statuses. */
    private const CUSTOMER_FILTERS = [
        'received' => ['received'],
        'confirmed' => ['confirmed'],
        'in_warehouse' => ['allocated', 'picking', 'packed'],
        'out_for_delivery' => ['dispatched'],
        'delivered' => ['delivered'],
        'returned' => ['returned'],
        'cancelled' => ['cancelled'],
    ];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_keys(self::CUSTOMER_FILTERS))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $orders = Order::query()
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($w) => $w
                ->where('order_no', 'like', "%{$q}%")
                ->orWhere('external_ref', 'like', "%{$q}%")
                ->orWhere('consignment_mark', 'like', "%{$q}%")
                ->orWhere('fba_reference', 'like', "%{$q}%")))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereIn('operational_status', self::CUSTOMER_FILTERS[$status]))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('requested_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('requested_date', '<=', $to))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('portal::orders.index', [
            'orders' => $orders,
            'filters' => $filters,
            'statuses' => array_keys(self::CUSTOMER_FILTERS),
        ]);
    }

    public function create(Request $request, RateService $rates): View
    {
        return view('portal::orders.create', $this->formData($this->clientId($request), $rates) + ['preview' => null]);
    }

    /** @return array<string, mixed> */
    private function formData(int $clientId, RateService $rates): array
    {
        return [
            // Item 6: the tailgate checkbox auto-ticks from the client's TR-TAILGATE threshold (rate card parameter, default 25 kg).
            'tailgateThresholdKg' => (float) ($rates->thresholds($clientId, 'TR-TAILGATE')['tailgate_weight_kg'] ?? TailgateRule::DEFAULT_WEIGHT_KG),
            'types' => ['from_stock', 'pickup_deliver'],
            'serviceLevels' => OrderEnums::SERVICE_LEVELS,
            'addressTypes' => OrderEnums::ADDRESS_TYPES,
            'states' => Enums::STATES,
            'addresses' => ClientAddress::query()->orderByDesc('usage_count')->orderByDesc('last_used_at')->orderBy('label')->get(), // A17 address book, client-scoped
        ];
    }

    public function store(Request $request, OrderCreationService $orders): RedirectResponse
    {
        $clientId = $this->clientId($request);
        $this->mergeSavedAddress($request, $clientId);
        OrderFormRows::prune($request); // spare form rows (package type select always has a value) are not lines

        $data = $request->validate($this->rules($clientId), $this->messages());

        $pickup = collect(['name' => 'pickup_name', 'phone' => 'pickup_phone', 'address' => 'pickup_address_line', 'suburb' => 'pickup_suburb', 'state' => 'pickup_state', 'postcode' => 'pickup_postcode'])
            ->map(fn ($field) => $data[$field] ?? null);
        $data['pickup_address'] = $pickup->filter(fn ($v) => filled($v))->isEmpty() ? null : $pickup->all();
        $data['client_id'] = $clientId; // never from the request: the signed-in client is the only possible owner

        $order = $orders->create($data, $request->user()->id, 'portal');
        try {
            app(OrderEstimateService::class)->estimate($order->fresh(), $request->user()->id); // the confirmed estimate travels with the order (tester feedback #10)
        } catch (\Throwable) {
            // estimate is informational — never block the order
        }

        return redirect()->route('portal.orders.show', $order)->with('status', __('portal.messages.created', ['order_no' => $order->order_no]));
    }

    /**
     * Tester feedback #10: "获取估价" before "确认提交订单" — validate the form, price it as a transient order (nothing saved) and
     * show the estimate on the same form so the client decides with the numbers in front of them.
     */
    public function preview(Request $request, RateService $rates, OrderEstimateService $estimates): View
    {
        $clientId = $this->clientId($request);
        $this->mergeSavedAddress($request, $clientId);
        OrderFormRows::prune($request);
        $data = $request->validate($this->rules($clientId), $this->messages());
        $request->flash(); // old() keeps every field the client typed

        $order = new Order(collect($data)->only((new Order)->getFillable())->all());
        $order->client_id = $clientId;
        $order->setRelation('client', Client::query()->findOrFail($clientId));
        $order->setRelation('lines', collect($data['lines'] ?? [])->map(fn (array $line) => new OrderLine(collect($line)->only((new OrderLine)->getFillable())->all())));
        $order->setRelation('declaredPackages', collect($data['declared_packages'] ?? [])->map(fn (array $p) => new DeclaredPackage(collect($p)->only((new DeclaredPackage)->getFillable())->all())));

        return view('portal::orders.create', $this->formData($clientId, $rates) + ['preview' => $estimates->preview($order)]);
    }

    /** Plain-Chinese messages for the goods rows — clients saw raw "lines.0.description_cn … required when …" text (tester feedback 2026-09-10). */
    private function messages(): array
    {
        return [
            'lines.required_unless' => __('portal.validation.lines_required'),
            'lines.*.description_cn.required_without' => __('portal.validation.line_name_required'),
            'lines.*.carton_qty.required' => __('portal.validation.line_qty_required'),
            'lines.*.carton_qty.min' => __('portal.validation.line_qty_required'),
            'lines.*.carton_qty.integer' => __('portal.validation.line_qty_required'),
            'lines.*.package_type.required' => __('portal.validation.line_package_required'),
            'lines.*.package_type.in' => __('portal.validation.line_package_required'),
            'declared_packages.*.qty.required' => __('portal.validation.package_qty_required'),
            'declared_packages.*.package_type.required' => __('portal.validation.package_type_required'),
        ];
    }

    /** @return array<string, mixed> */
    private function rules(int $clientId): array
    {
        return [
            'order_type' => ['required', Rule::in(['from_stock', 'pickup_deliver'])],
            'external_ref' => ['nullable', 'string', 'max:255', Rule::unique('orders', 'external_ref')->where(fn ($q) => $q->where('client_id', $clientId))],
            'consignment_mark' => ['nullable', 'string', 'max:255'],
            'fba_reference' => ['nullable', 'string', 'max:255'],
            'client_address_id' => ['nullable', 'integer', Rule::exists('client_addresses', 'id')->where(fn ($q) => $q->where('client_id', $clientId))],
            'deliver_to_name' => ['required', 'string', 'max:255'],
            'deliver_to_phone' => ['nullable', 'string', 'max:40'],
            'deliver_to_address' => ['required', 'string', 'max:255'],
            'deliver_to_suburb' => ['required', 'string', 'max:100'],
            'deliver_to_state' => ['required', Rule::in(Enums::STATES)],
            'deliver_to_postcode' => ['required', 'string', 'max:10'],
            'deliver_to_address_type' => ['required', Rule::in(OrderEnums::ADDRESS_TYPES)],
            'delivery_instructions' => ['nullable', 'string', 'max:2000'],
            'requested_date' => ['required', 'date', 'after_or_equal:today'],
            'service_level' => ['required', Rule::in(OrderEnums::SERVICE_LEVELS)],
            'tailgate_required' => ['nullable', 'boolean'], // item 6: checkbox state; only stored as given when tailgate_manual is set
            'tailgate_manual' => ['nullable', 'boolean'],
            'pickup_name' => ['nullable', 'string', 'max:255'],
            'pickup_phone' => ['nullable', 'string', 'max:40'],
            'pickup_address_line' => ['nullable', 'required_if:order_type,pickup_deliver', 'string', 'max:255'],
            'pickup_suburb' => ['nullable', 'required_if:order_type,pickup_deliver', 'string', 'max:100'],
            'pickup_state' => ['nullable', 'required_if:order_type,pickup_deliver', Rule::in(Enums::STATES)],
            'pickup_postcode' => ['nullable', 'required_if:order_type,pickup_deliver', 'string', 'max:10'],
            'lines' => ['nullable', 'required_unless:order_type,pickup_deliver', 'array'],
            'lines.*.description_cn' => ['nullable', 'string', 'max:255', 'required_without:lines.*.description_en'], // one Chinese message for "neither name given" (messages())
            'lines.*.description_en' => ['nullable', 'string', 'max:255'],
            'lines.*.package_type' => ['required', Rule::in(OrderEnums::PACKAGE_TYPES)],
            'lines.*.carton_qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit_qty' => ['nullable', 'integer', 'min:0'],
            'lines.*.actual_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'lines.*.length_mm' => ['nullable', 'integer', 'min:0'],
            'lines.*.width_mm' => ['nullable', 'integer', 'min:0'],
            'lines.*.height_mm' => ['nullable', 'integer', 'min:0'],
            'declared_packages' => ['nullable', 'required_if:order_type,pickup_deliver', 'array'],
            'declared_packages.*.package_type' => ['required_with:declared_packages.*.qty', 'nullable', Rule::in(OrderEnums::PACKAGE_TYPES)],
            'declared_packages.*.qty' => ['required_with:declared_packages.*.package_type', 'nullable', 'integer', 'min:1'],
            'declared_packages.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'declared_packages.*.length_mm' => ['nullable', 'integer', 'min:0'],
            'declared_packages.*.width_mm' => ['nullable', 'integer', 'min:0'],
            'declared_packages.*.height_mm' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function show(int $order, OrderEstimateService $estimates, PortalTransportQuotes $transportQuotes): View
    {
        // Resolved here, not by implicit binding: SubstituteBindings runs before the client.scope middleware sets the tenant,
        // so only a query issued inside the action is filtered to the signed-in client (another client's order → 404).
        $order = Order::query()->with(['lines', 'declaredPackages', 'fulfilments.lines.orderLine', 'originalOrder', 'returnOrders'])->findOrFail($order);

        // Transport tracking, read-only from X2's tables; the client scope on Shipment keeps it to this client.
        $shipments = Shipment::query()->where('order_id', $order->id)->with(['carrier', 'trackingEvents', 'pods.podDocument'])->orderBy('id')->get();

        return view('portal::orders.show', [
            'order' => $order,
            'timeline' => $order->events()->where('dimension', 'operational')->where(fn ($q) => $q->whereColumn('from_status', '!=', 'to_status')->orWhereNull('from_status'))->get(), // status steps only — no internal notes
            'shipments' => $shipments,
            'canRequestReturn' => $order->acceptsReturnRequest(),
            // A7b: the client's estimate — customer prices only (OrderEstimateService never selects cost).
            'estimate' => $estimates->current($order),
            'canEstimate' => $estimates->canEstimate($order) && auth()->user()->isClientUser(),
            // §5.7 #2: final carrier quotes to confirm (customer price only; confirmation goes through Transport's QuoteSelectionService).
            'transportQuotes' => $transportQuotes->forOrder($order),
        ]);
    }

    /** Portal orders belong to the signed-in client user; staff use /orders. */
    private function clientId(Request $request): int
    {
        $user = $request->user();
        abort_unless($user?->isClientUser() && $user->client_id !== null, 403, __('portal.messages.client_only'));

        return (int) $user->client_id;
    }

    private function mergeSavedAddress(Request $request, int $clientId): void
    {
        if (! $request->filled('client_address_id')) {
            return;
        }
        $address = ClientAddress::query()->whereKey($request->integer('client_address_id'))->where('client_id', $clientId)->first();
        if (! $address) {
            return;
        }

        $request->merge(collect([
            'deliver_to_name' => $address->contact_name ?: $address->label,
            'deliver_to_phone' => $address->phone,
            'deliver_to_address' => $address->address,
            'deliver_to_suburb' => $address->suburb,
            'deliver_to_state' => $address->state,
            'deliver_to_postcode' => $address->postcode,
            'deliver_to_address_type' => $address->address_type,
            'delivery_instructions' => $address->default_instructions,
        ])->reject(fn ($value, $field) => $request->filled($field))->all());
    }
}
