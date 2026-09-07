<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Services\ShipmentMarginService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderMarginController extends Controller
{
    public function __invoke(Request $request, int $orderId, ShipmentMarginService $margins): View
    {
        abort_unless($request->user()?->hasAnyRole(['admin', 'customer_service', 'dispatcher', 'transport_operator', 'finance']), 403);
        $summary = $margins->order($orderId);
        abort_if($summary['shipments'] === [], 404);
        $orderNo = Schema::hasTable('orders')
            ? DB::table('orders')->where('id', $orderId)->value('order_no')
            : null;

        return view('transport::orders.margin', [
            'summary' => $summary,
            'orderNo' => $orderNo ?: (string) $orderId,
        ]);
    }
}
