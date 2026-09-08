<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Services\AddressSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Item 3b: `GET /portal/addresses/suggest?q=` — the signed-in client's own address book + past deliver-to addresses, never another client's. */
final class PortalAddressSuggestionController extends Controller
{
    public function __invoke(Request $request, AddressSuggestionService $suggestions): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->isClientUser() && $user->client_id !== null, 403, __('portal.messages.client_only'));
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json($suggestions->suggest((int) $user->client_id, (string) ($data['q'] ?? '')));
    }
}
