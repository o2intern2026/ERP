<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Warehouse\Models\GoodsReceipt;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Services\GoodsReceiptService;
use App\Modules\Warehouse\Services\WarehouseContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** 入库单 (goods receipt): list, page with the ASN roll-up, 入库完成 and the live PDF (CHANGE_REQUESTS #90). */
class GoodsReceiptController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(['open', 'completed'])],
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'],
        ]);

        return view('warehouse::receipts.index', [
            'receipts' => GoodsReceipt::query()->with(['asn', 'client', 'warehouse'])->withCount('lines')
                ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
                ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('opened_at', '>=', $v))
                ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('opened_at', '<=', $v))
                ->when(WarehouseContext::currentId(), fn ($q, $v) => $q->where('warehouse_id', $v))
                ->orderByDesc('id')->paginate(30)->withQueryString(),
            'filters' => $filters,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(GoodsReceipt $receipt, GoodsReceiptService $receipts): View
    {
        $receipt->load(['asn.goodsReceipts', 'client', 'warehouse', 'job', 'openedBy', 'completedBy', 'lines.asnLine.container', 'lines.receivedBy']);

        return view('warehouse::receipts.show', [
            'receipt' => $receipt,
            'asn' => $receipt->asn,
            'batches' => $receipt->asn->goodsReceipts->loadCount('lines'),
            'rollup' => $receipts->rollup($receipt->asn),
            'labels' => StockUnit::query()->where('goods_receipt_id', $receipt->id)->orderBy('id')->get()->groupBy('asn_line_id'),
        ]);
    }

    public function complete(Request $request, GoodsReceipt $receipt, GoodsReceiptService $receipts): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        try {
            $receipts->complete($receipt, $request->user()?->id, $data['notes'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['complete' => $e->getMessage()]);
        }

        return redirect()->route('warehouse.receipts.show', $receipt)->with('status', __('warehouse.receipts.completed', ['no' => $receipt->receipt_no]));
    }

    /** Always rendered live from the database (open → 草稿); the file stored at completion is what the document centre / portal serve. */
    public function pdf(GoodsReceipt $receipt, GoodsReceiptService $receipts): Response
    {
        return response($receipts->pdf($receipt), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$receipt->receipt_no.'.pdf"']);
    }
}
