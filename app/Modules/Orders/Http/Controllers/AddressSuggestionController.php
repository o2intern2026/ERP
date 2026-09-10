<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Services\AddressSuggestionService;
use App\Support\Auth\RequiredRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Item 3b: `GET /orders/addresses/suggest?q=&client_id=` — JSON for the staff order form's address dropdown (same roles as order entry). */
final class AddressSuggestionController extends Controller
{
    public function __invoke(Request $request, AddressSuggestionService $suggestions): JsonResponse
    {
        RequiredRoles::requireAny(['admin', 'customer_service', 'dispatcher']);
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($suggestions->suggest((int) $data['client_id'], (string) ($data['q'] ?? '')));
    }
}
