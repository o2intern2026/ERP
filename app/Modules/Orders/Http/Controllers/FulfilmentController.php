<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\FulfilmentService;
use Illuminate\Contracts\View\View;

final class FulfilmentController extends Controller
{
    public function index(Order $order, FulfilmentService $fulfilments): View
    {
        return view('orders::fulfilments.index', [
            'order' => $order->load(['client', 'lines', 'fulfilments.lines.orderLine']),
            'availability' => $fulfilments->availability($order),
        ]);
    }
}
