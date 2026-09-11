<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Services\OrderApiTokenService;
use App\Modules\Portal\Http\PortalValidation;
use App\Modules\Portal\Services\PortalAsnService;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\Enums;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * CHANGE_REQUESTS #116 "有能力的客户走 API 推送": POST /portal/api/asns with the client API token issued at /orders/api-tokens
 * (the same Bearer credential as the order API), JSON header + containers + lines, optional Idempotency-Key. The token names the
 * client — never the body. Creation is PortalAsnService::submitFromApi → Warehouse AsnService, exactly like the portal form.
 */
final class PortalAsnApiController extends Controller
{
    public function store(Request $request, OrderApiTokenService $tokens, PortalAsnService $service): JsonResponse
    {
        $token = $tokens->authenticate($request->bearerToken());
        if ($token === null) {
            return response()->json(['error' => 'unauthenticated', 'message' => __('portal.asns.api.errors.unauthenticated')], 401);
        }

        $validator = Validator::make($request->json()->all(), $this->rules(), PortalValidation::messages(), PortalValidation::attributes());
        if ($validator->fails()) {
            return response()->json(['error' => 'validation_failed', 'errors' => $validator->errors()->toArray()], 422);
        }
        $data = $validator->validated();

        $warehouse = Warehouse::query()->where('active', true)
            ->when($data['warehouse_code'] ?? null, fn ($q, $code) => $q->where('code', $code))
            ->orderBy('code')->first();
        if ($warehouse === null) {
            return response()->json(['error' => 'validation_failed', 'errors' => ['warehouse_code' => [__('portal.asns.api.errors.warehouse_unknown')]]], 422);
        }
        $data['warehouse_id'] = $warehouse->id;

        $containerNos = collect($data['containers'] ?? [])->pluck('container_no')->all();
        foreach ($data['lines'] as $i => $line) {
            if (filled($line['container_no'] ?? null) && ! in_array($line['container_no'], $containerNos, true)) {
                return response()->json(['error' => 'validation_failed', 'errors' => ["lines.{$i}.container_no" => [__('portal.asns.api.errors.container_unknown', ['no' => $line['container_no']])]]], 422);
            }
        }

        ['asn' => $asn, 'replayed' => $replayed] = $service->submitFromApi((int) $token->client_id, $token->id, $data, $request->header('Idempotency-Key'));

        return $this->asnResponse($asn, $replayed, $replayed ? 200 : 201);
    }

    private function asnResponse(Asn $asn, bool $replayed, int $status): JsonResponse
    {
        return response()->json([
            'asn_id' => $asn->id,
            'asn_no' => $asn->asn_no,
            'job_no' => $asn->job()->withoutGlobalScopes()->value('job_no'),
            'status' => $asn->status,
            'confirmation' => $asn->isPendingClientConfirmation() ? 'pending' : 'confirmed',
            'lines' => $asn->lines()->count(),
            'replayed' => $replayed,
        ], $status);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'inbound_type' => ['required', Rule::in(Enums::INBOUND_TYPES)],
            'expected_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'warehouse_code' => ['nullable', 'string', 'max:20'],
            'containers' => ['nullable', 'required_if:inbound_type,container', 'array', 'max:10'],
            'containers.*.container_no' => ['required', 'string', 'max:20', 'distinct'],
            'containers.*.size' => ['required', Rule::in(Enums::CONTAINER_SIZES)],
            'containers.*.unpack_mode' => ['nullable', Rule::in(Enums::UNPACK_MODES)],
            'containers.*.gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:2000'],
            'lines.*.container_no' => ['nullable', 'string', 'max:20'],
            'lines.*.consignment_mark' => ['nullable', 'string', 'max:60'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.expected_cartons' => ['required', 'integer', 'min:0'],
            'lines.*.package_type' => ['nullable', 'string', 'max:40'],
            'lines.*.deliver_to_name' => ['nullable', 'string', 'max:255'],
            'lines.*.deliver_to_phone' => ['nullable', 'string', 'max:40'],
            'lines.*.deliver_to_address' => ['nullable', 'string', 'max:255'],
            'lines.*.deliver_to_suburb' => ['nullable', 'string', 'max:100'],
            'lines.*.deliver_to_state' => ['nullable', Rule::in(Enums::STATES)],
            'lines.*.deliver_to_postcode' => ['nullable', 'string', 'max:10'],
            'lines.*.fba_reference' => ['nullable', 'string', 'max:60'],
            'lines.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'lines.*.length_mm' => ['nullable', 'integer', 'min:0'],
            'lines.*.width_mm' => ['nullable', 'integer', 'min:0'],
            'lines.*.height_mm' => ['nullable', 'integer', 'min:0'],
            'lines.*.cbm' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
