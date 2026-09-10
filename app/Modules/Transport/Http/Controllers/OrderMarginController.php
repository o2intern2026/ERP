<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Services\ShipmentMarginService;
use App\Support\Auth\RequiredRoles;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderMarginController extends Controller
{
    public function __invoke(Request $request, int $orderId, ShipmentMarginService $margins): View
    {
        RequiredRoles::requireAny(['admin', 'customer_service', 'dispatcher', 'transport_operator', 'finance']);
        $orderNo = Schema::hasTable('orders')
            ? DB::table('orders')->where('id', $orderId)->value('order_no')
            : null;
        $summary = $margins->order($orderId);
        // 2026-09-10 audit: 404 only when nothing is known about the order. An existing order with no shipment yet (before the
        // outbox consumer books one, or a 退货 order that never gets one) renders an empty state — the Orders page links here from every order.
        abort_if($orderNo === null && $summary['shipments'] === [], 404);

        return view('transport::orders.margin', [
            'summary' => $summary,
            'orderNo' => $orderNo ?: (string) $orderId,
        ]);
    }
}
