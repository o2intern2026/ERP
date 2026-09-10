<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\OrderValidation;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Services\OrderChangeService;
use App\Modules\Orders\Services\OrderStatusService;
use App\Support\Auth\RequiredRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A12: goods lines of a not-yet-confirmed order (drafts read from a PDF / e-mail) can be corrected or added by a
 * coordinator. After confirmation Warehouse holds reservations, so only A11's reduction applies.
 */
final class OrderLineController extends Controller
{
    public function store(Request $request, Order $order, OrderStatusService $statuses): RedirectResponse
    {
        $this->authorizeDraft($order);
        $data = $this->validated($request);

        DB::transaction(function () use ($order, $data, $request, $statuses): void {
            // 2026-09-10 audit: the submitted package type wins; 'carton' is only the fallback (array union keeps the LEFT key).
            $line = $order->lines()->create(array_filter($data, fn ($v) => $v !== null) + ['package_type' => 'carton']);
            $statuses->note($order, $request->user()->id, __('orders.drafts.timeline.line_added', ['description' => $line->description_cn ?: $line->description_en, 'qty' => $line->carton_qty]));
        });

        return redirect()->route('orders.show', $order)->with('status', __('orders.drafts.messages.line_saved'));
    }

    public function update(Request $request, Order $order, OrderLine $line, OrderStatusService $statuses): RedirectResponse
    {
        $this->authorizeDraft($order);
        abort_unless($line->order_id === $order->id, 404);
        $data = $this->validated($request);

        DB::transaction(function () use ($order, $line, $data, $request, $statuses): void {
            $line->update($data);
            $statuses->note($order, $request->user()->id, __('orders.drafts.timeline.line_updated', ['description' => $line->description_cn ?: $line->description_en, 'qty' => $line->carton_qty]));
        });

        return redirect()->route('orders.show', $order)->with('status', __('orders.drafts.messages.line_saved'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $order = $request->route('order');

        return $request->validate([
            'description_cn' => ['nullable', 'string', 'max:255', 'required_without:description_en'],
            'description_en' => ['nullable', 'string', 'max:255'], // either/or check on description_cn only (one message)
            'package_type' => ['nullable', 'string', 'max:30'],
            'carton_qty' => ['required', 'integer', 'min:1'],
            'unit_qty' => ['nullable', 'integer', 'min:0'],
            'actual_weight_kg' => ['nullable', 'numeric', 'min:0'],
            // A7 needs a goods line (asn_line_id) before a from_stock order can be confirmed: the coordinator links the draft line to the client's ASN line (read-only lookup).
            'asn_line_id' => ['nullable', 'integer', Rule::exists('asn_lines', 'id')->where(fn ($q) => $q->whereIn('asn_id', DB::table('asns')->where('client_id', $order->client_id)->select('id')))],
        ], OrderValidation::messages(), OrderValidation::attributes());
    }

    private function authorizeDraft(Order $order): void
    {
        RequiredRoles::requireAny(OrderChangeService::COORDINATOR_ROLES);
        abort_unless($order->operational_status === 'received', 403, __('orders.drafts.messages.confirmed_locked'));
    }
}
