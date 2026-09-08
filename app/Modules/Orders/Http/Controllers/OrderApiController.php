<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderApiIdempotencyKey;
use App\Modules\Orders\OrderEnums;
use App\Modules\Orders\Services\OrderApiTokenService;
use App\Modules\Orders\Services\OrderCreationService;
use App\Support\Enums;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A4b / OMS-1 "client system pushes orders" — interface reserved, no real client yet. Bearer token (order_api_tokens) →
 * client; optional Idempotency-Key header → a replay returns the original order; creation goes through
 * OrderCreationService only (source = api). Lives under /orders/** (contracts/routes.md; CHANGE_REQUESTS #39).
 */
final class OrderApiController extends Controller
{
    public function store(Request $request, OrderApiTokenService $tokens, OrderCreationService $orders): JsonResponse
    {
        $token = $tokens->authenticate($request->bearerToken());
        if ($token === null) {
            return response()->json(['error' => 'unauthenticated', 'message' => __('orders.api.errors.unauthenticated')], 401);
        }
        $clientId = $token->client_id;

        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key !== '') {
            $existing = OrderApiIdempotencyKey::query()->where('client_id', $clientId)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $this->orderResponse(Order::query()->withoutGlobalScopes()->findOrFail($existing->order_id), true, 200);
            }
        }

        $validator = Validator::make($request->json()->all(), $this->rules($clientId));
        if ($validator->fails()) {
            return response()->json(['error' => 'validation_failed', 'errors' => $validator->errors()->toArray()], 422);
        }
        $data = $validator->validated();
        $data['client_id'] = $clientId; // the token decides the client, never the body

        try {
            $order = DB::transaction(function () use ($orders, $data, $clientId, $key): Order {
                $order = $orders->create($data, null, 'api');
                if ($key !== '') {
                    OrderApiIdempotencyKey::query()->create(['client_id' => $clientId, 'idempotency_key' => $key, 'order_id' => $order->id, 'created_at' => now()]);
                }

                return $order;
            });
        } catch (QueryException $e) {
            // Two identical requests raced on the unique (client_id, idempotency_key): return the one that won.
            $existing = $key !== '' ? OrderApiIdempotencyKey::query()->where('client_id', $clientId)->where('idempotency_key', $key)->first() : null;
            if ($existing === null) {
                throw $e;
            }

            return $this->orderResponse(Order::query()->withoutGlobalScopes()->findOrFail($existing->order_id), true, 200);
        }

        return $this->orderResponse($order, false, 201);
    }

    private function orderResponse(Order $order, bool $replayed, int $status): JsonResponse
    {
        return response()->json([
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'operational_status' => $order->operational_status,
            'customer_status' => $order->customerStatus(),
            'replayed' => $replayed,
        ], $status);
    }

    /** @return array<string, mixed> */
    private function rules(int $clientId): array
    {
        return [
            'order_type' => ['required', Rule::in(['from_stock', 'pickup_deliver'])],
            'external_ref' => ['nullable', 'string', 'max:255', Rule::unique('orders', 'external_ref')->where(fn ($q) => $q->where('client_id', $clientId))],
            'consignment_mark' => ['nullable', 'string', 'max:255'],
            'fba_reference' => ['nullable', 'string', 'max:255'],
            'deliver_to_name' => ['required', 'string', 'max:255'],
            'deliver_to_phone' => ['nullable', 'string', 'max:40'],
            'deliver_to_address' => ['required', 'string', 'max:255'],
            'deliver_to_suburb' => ['required', 'string', 'max:100'],
            'deliver_to_state' => ['required', Rule::in(Enums::STATES)],
            'deliver_to_postcode' => ['required', 'string', 'max:10'],
            'deliver_to_address_type' => ['nullable', Rule::in(OrderEnums::ADDRESS_TYPES)],
            'delivery_instructions' => ['nullable', 'string', 'max:2000'],
            'requested_date' => ['required', 'date'],
            'service_level' => ['nullable', Rule::in(OrderEnums::SERVICE_LEVELS)],
            'pickup_address' => ['nullable', 'required_if:order_type,pickup_deliver', 'array'],
            'pickup_address.name' => ['nullable', 'string', 'max:255'],
            'pickup_address.phone' => ['nullable', 'string', 'max:40'],
            'pickup_address.address' => ['required_with:pickup_address', 'string', 'max:255'],
            'pickup_address.suburb' => ['required_with:pickup_address', 'string', 'max:100'],
            'pickup_address.state' => ['required_with:pickup_address', Rule::in(Enums::STATES)],
            'pickup_address.postcode' => ['required_with:pickup_address', 'string', 'max:10'],
            'lines' => ['nullable', 'required_unless:order_type,pickup_deliver', 'array'],
            'lines.*.description_cn' => ['nullable', 'string', 'max:255', 'required_without:lines.*.description_en'],
            'lines.*.description_en' => ['nullable', 'string', 'max:255', 'required_without:lines.*.description_cn'],
            'lines.*.package_type' => ['nullable', 'string', 'max:30'],
            'lines.*.carton_qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit_qty' => ['nullable', 'integer', 'min:0'],
            'lines.*.actual_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'lines.*.length_mm' => ['nullable', 'integer', 'min:0'],
            'lines.*.width_mm' => ['nullable', 'integer', 'min:0'],
            'lines.*.height_mm' => ['nullable', 'integer', 'min:0'],
            'lines.*.cbm' => ['nullable', 'numeric', 'min:0'],
            'lines.*.asn_line_id' => ['nullable', 'integer', Rule::exists('asn_lines', 'id')->where(fn ($q) => $q->whereIn('asn_id', DB::table('asns')->where('client_id', $clientId)->select('id')))],
            'declared_packages' => ['nullable', 'required_if:order_type,pickup_deliver', 'array'],
            'declared_packages.*.package_type' => ['required', 'string', 'max:30'],
            'declared_packages.*.qty' => ['required', 'integer', 'min:1'],
            'declared_packages.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'declared_packages.*.length_mm' => ['nullable', 'integer', 'min:0'],
            'declared_packages.*.width_mm' => ['nullable', 'integer', 'min:0'],
            'declared_packages.*.height_mm' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
