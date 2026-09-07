<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Warehouse\Models\ReturnReceipt;
use App\Modules\Warehouse\Models\ReturnReceiptLine;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\ReturnService;
use App\Modules\Warehouse\Services\WarehouseContext;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** B13 pages: open a return receipt against the original order, receive line by line, inspect, complete. */
class ReturnController extends Controller
{
    public function index(): View
    {
        return view('warehouse::returns.index', [
            'receipts' => ReturnReceipt::query()->with(['client', 'warehouse'])->withCount('lines')->when(WarehouseContext::currentId(), fn ($q, $v) => $q->where('warehouse_id', $v))->orderByDesc('id')->paginate(50),
            'orderNos' => fn ($ids) => DB::table('orders')->whereIn('id', $ids)->pluck('order_no', 'id'),
            'warehouses' => Warehouse::query()->where('active', true)->orderBy('code')->get(),
            'currentWarehouseId' => WarehouseContext::currentId(),
        ]);
    }

    public function store(Request $request, ReturnService $returns): RedirectResponse
    {
        $data = $request->validate(['order_no' => ['required', 'string', 'max:40'], 'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')], 'notes' => ['nullable', 'string', 'max:1000']]);
        $orderId = DB::table('orders')->where('order_no', trim($data['order_no']))->value('id');
        if ($orderId === null) {
            return back()->withErrors(['order_no' => __('warehouse.returns.unknown_order', ['order_no' => $data['order_no']])])->withInput();
        }

        $receipt = $returns->open(['original_order_id' => (int) $orderId, 'warehouse_id' => (int) $data['warehouse_id'], 'notes' => $data['notes'] ?? null]);

        return redirect()->route('warehouse.returns.show', $receipt)->with('status', __('warehouse.returns.opened', ['receipt_no' => $receipt->receipt_no]));
    }

    public function show(ReturnReceipt $receipt): View
    {
        $receipt->load(['client', 'warehouse', 'lines.stockUnit.location']);

        return view('warehouse::returns.show', [
            'receipt' => $receipt,
            'orderNo' => DB::table('orders')->where('id', $receipt->original_order_id)->value('order_no'),
            'conditions' => Enums::CONDITIONS,
            'dispositions' => Enums::RETURN_DISPOSITIONS,
        ]);
    }

    public function receive(Request $request, ReturnReceipt $receipt, ReturnReceiptLine $line, ReturnService $returns): RedirectResponse
    {
        abort_unless($line->return_receipt_id === $receipt->id, 404);
        $data = $request->validate(['received_qty' => ['required', 'integer', 'min:0'], 'condition' => ['required', Rule::in(Enums::CONDITIONS)]]);

        return $this->attempt(fn () => $returns->receiveLine($line, (int) $data['received_qty'], $data['condition']), 'received_ok', 'received_qty');
    }

    public function completeReceiving(ReturnReceipt $receipt, ReturnService $returns): RedirectResponse
    {
        return $this->attempt(fn () => $returns->completeReceiving($receipt), 'receiving_completed', 'receipt');
    }

    public function inspect(Request $request, ReturnReceipt $receipt, ReturnReceiptLine $line, ReturnService $returns): RedirectResponse
    {
        abort_unless($line->return_receipt_id === $receipt->id, 404);
        $data = $request->validate(['disposition' => ['required', Rule::in(Enums::RETURN_DISPOSITIONS)]]);

        return $this->attempt(fn () => $returns->inspectLine($line, $data['disposition'], auth()->id()), 'inspected_ok', 'disposition');
    }

    public function completeInspection(ReturnReceipt $receipt, ReturnService $returns): RedirectResponse
    {
        return $this->attempt(fn () => $returns->completeInspection($receipt, auth()->id()), 'inspection_completed', 'receipt');
    }

    private function attempt(callable $action, string $statusKey, string $errorField): RedirectResponse
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors([$errorField => $e->getMessage()]);
        }

        return back()->with('status', __('warehouse.returns.'.$statusKey));
    }
}
