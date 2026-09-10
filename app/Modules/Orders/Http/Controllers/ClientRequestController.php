<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Services\ClientRequestService;
use App\Modules\Orders\Services\OrderChangeService;
use App\Support\Auth\RequiredRoles;
use Illuminate\Contracts\View\View;

/** 客户请求 inbox — see ClientRequestService (CR #112). */
class ClientRequestController extends Controller
{
    public function index(ClientRequestService $requests, OrderChangeService $changes): View
    {
        RequiredRoles::requireAny(ClientRequestService::STAFF_ROLES);
        $user = auth()->user();
        $cancels = $requests->pendingCancelRequests();

        return view('orders::requests.index', [
            'cancels' => $cancels,
            'canExecute' => $cancels->mapWithKeys(fn ($e) => [$e->id => $e->order !== null && $changes->canChange($e->order, $user)])->all(),
            'canDecide' => $user->hasAnyRole(OrderChangeService::COORDINATOR_ROLES),
            'returns' => $requests->pendingReturnRequests(),
            'history' => $requests->history(),
        ]);
    }
}
