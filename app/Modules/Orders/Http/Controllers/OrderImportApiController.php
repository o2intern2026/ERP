<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\Services\AutoImportService;
use App\Modules\Orders\Services\OrderApiTokenService;
use App\Modules\Orders\Services\OrderImportService;
use App\Support\Enums;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * CHANGE_REQUESTS #145 自动导入 — the client's system pushes a WHOLE list (the same CSV / XLSX the portal upload takes, the real
 * consolidation format included) and gets the result back: `POST /orders/api/imports` (multipart) and `GET /orders/api/imports/{id}`.
 * Same bearer token as `/orders/api/orders` (the token names the client; no session, CSRF or client scope). Everything goes through
 * AutoImportService → OrderImportService: options fall back to the client's import defaults, auto-confirm only on a clean list, a
 * replayed file returns the earlier import. 201 = orders created, 202 = pending in the portal, 200 = replay / nothing created.
 */
final class OrderImportApiController extends Controller
{
    public function store(Request $request, OrderApiTokenService $tokens, AutoImportService $auto): JsonResponse
    {
        $token = $tokens->authenticate($request->bearerToken());
        if ($token === null) {
            return response()->json(['error' => 'unauthenticated', 'message' => __('orders.api.errors.unauthenticated')], 401);
        }
        $client = Client::query()->withoutGlobalScopes()->find($token->client_id);
        if ($client === null || $client->status !== 'active') {
            return response()->json(['error' => 'client_inactive', 'message' => __('orders.api.errors.client_inactive')], 403);
        }

        $validator = Validator::make($request->all(), $this->rules($request->input('order_type') === 'pickup_deliver'));
        if ($validator->fails()) {
            return response()->json(['error' => 'validation_failed', 'errors' => $validator->errors()->toArray()], 422);
        }
        $data = $validator->validated();

        $result = $auto->import($client, $data['manifest'], Arr::except($data, ['manifest']), 'api');
        $summary = $auto->summary($result['import'], $result['replayed'], $result['auto_confirmed']);
        $status = $result['replayed'] ? 200 : match ($summary['status']) {
            'imported' => 201,
            'pending' => 202,
            default => 200,
        };

        return response()->json($summary, $status);
    }

    /** The result of one of the client's own submissions (API, inbox or portal) — for polling a list left pending. */
    public function show(Request $request, int $import, OrderApiTokenService $tokens, AutoImportService $auto): JsonResponse
    {
        $token = $tokens->authenticate($request->bearerToken());
        if ($token === null) {
            return response()->json(['error' => 'unauthenticated', 'message' => __('orders.api.errors.unauthenticated')], 401);
        }
        $model = OrderImport::query()->withoutGlobalScopes()->where('client_id', $token->client_id)
            ->whereIn('source', AutoImportService::CLIENT_SOURCES)->whereKey($import)->first();
        if ($model === null || $model->status === 'draft') {
            return response()->json(['error' => 'not_found', 'message' => __('orders.api.errors.import_not_found')], 404);
        }

        return response()->json($auto->summary($model));
    }

    /** @return array<string, mixed> */
    private function rules(bool $pickupDeliver): array
    {
        $pickup = fn (array $rules): array => [$pickupDeliver ? 'required' : 'nullable', ...$rules];

        return [
            // The same file rule as the portal upload (PortalInboundImportController::store): CSV / XLSX / XLS / TXT, ≤ 10 MB.
            'manifest' => ['required', 'file', 'max:10240', function ($attribute, $value, $fail) {
                if (! in_array(mb_strtolower($value->getClientOriginalExtension()), AutoImportService::INBOX_EXTENSIONS, true)) {
                    $fail(__('portal.inbound.errors.unsupported_file'));
                }
            }],
            'order_type' => ['nullable', Rule::in(OrderImportService::ORDER_TYPES)],
            'group_by' => ['nullable', Rule::in(Enums::IMPORT_GROUP_BYS)],
            'address_type_default' => ['nullable', Rule::in(Enums::IMPORT_ADDRESS_TYPE_DEFAULTS)],
            'auto_confirm' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
            'container_no' => ['nullable', 'string', 'max:20'],
            'container_size' => ['nullable', Rule::in(Enums::CONTAINER_SIZES)],
            'expected_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'requested_date' => ['nullable', 'date', 'after_or_equal:today'],
            'pickup' => $pickup(['array']),
            'pickup.name' => $pickup(['string', 'max:255']),
            'pickup.phone' => ['nullable', 'string', 'max:40'],
            'pickup.address' => $pickup(['string', 'max:255']),
            'pickup.suburb' => $pickup(['string', 'max:100']),
            'pickup.state' => $pickup([Rule::in(Enums::STATES)]),
            'pickup.postcode' => $pickup(['regex:/^\d{4}$/']),
        ];
    }
}
