<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Http\PortalValidation;
use App\Modules\Portal\Services\PortalAsnService;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnImport;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * 客户自助预报入库 (CHANGE_REQUESTS #116): the client's own ASNs — list, submit (packing list + container + ETA), detail with the
 * 入库单 PDFs, 补传装箱单 while still a draft. Isolation is the data-layer client scope on `asns`; another client's ASN is a 404.
 */
final class PortalAsnController extends Controller
{
    /** Column headers the shared manifest parser recognises (SpreadsheetManifestParser::HEADERS), in the order of the client list. */
    public const TEMPLATE_HEADERS = ['唛头', '中文品名', '英文品名', '外包装种类', '箱数', '产品数量', '实重kg', '长cm', '宽cm', '高cm', '总方数', '收件人公司名/人名', '联系方式', '收件人地址', '州', '邮编', 'FBA Shipment ID'];

    public function index(Request $request): View
    {
        $this->clientId($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Enums::ASN_STATUSES)],
        ], PortalValidation::messages(), PortalValidation::attributes());

        $asns = Asn::query()->with(['warehouse', 'containers', 'job'])->withCount('lines')
            ->withSum('lines', 'expected_cartons')->withSum('lines', 'received_cartons')
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($w) => $w
                ->where('asn_no', 'like', "%{$q}%")
                ->orWhereHas('containers', fn ($c) => $c->where('container_no', 'like', "%{$q}%"))
                ->orWhereHas('job', fn ($j) => $j->where('reference', 'like', "%{$q}%"))))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return view('portal::asns.index', ['asns' => $asns, 'filters' => $filters, 'statuses' => Enums::ASN_STATUSES]);
    }

    public function create(Request $request, PortalAsnService $service): View
    {
        $this->clientId($request);

        return view('portal::asns.create', [
            'warehouses' => $service->warehouses(),
            'inboundTypes' => Enums::INBOUND_TYPES,
            'containerSizes' => Enums::CONTAINER_SIZES,
            'unpackModes' => Enums::UNPACK_MODES,
            'templateHeaders' => self::TEMPLATE_HEADERS,
        ]);
    }

    public function store(Request $request, PortalAsnService $service): RedirectResponse
    {
        $clientId = $this->clientId($request);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('active', true)],
            'inbound_type' => ['required', Rule::in(Enums::INBOUND_TYPES)],
            'container_no' => ['nullable', 'string', 'max:20', 'required_if:inbound_type,container'],
            'container_size' => ['nullable', Rule::in(Enums::CONTAINER_SIZES), 'required_if:inbound_type,container'],
            'unpack_mode' => ['nullable', Rule::in(Enums::UNPACK_MODES)],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'expected_date' => ['required', 'date', 'after_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'packing_list' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ], PortalValidation::messages(), PortalValidation::attributes());

        ['asn' => $asn, 'import' => $import] = $service->submitFromPortal($clientId, (int) $request->user()->id, $data, $data['packing_list']);

        return redirect()->route('portal.asns.show', $asn)
            ->with('status', __('portal.asns.messages.submitted', ['asn_no' => $asn->asn_no, 'rows' => $import->row_count, 'errors' => $import->error_count]));
    }

    public function show(Request $request, Asn $asn): View
    {
        $this->clientId($request);
        $asn->load(['warehouse', 'job', 'containers', 'lines.container', 'lines.stockUnits', 'lines.receiptLine', 'goodsReceipts.pdfDocument', 'clientConfirmedBy']);

        return view('portal::asns.show', [
            'asn' => $asn,
            'imports' => AsnImport::query()->where('asn_id', $asn->id)->orderByDesc('id')->get(),
            'canReplace' => $asn->isPendingClientConfirmation() && $asn->status === 'booked' && ! $asn->lines->contains(fn ($l) => $l->isReceived() || $l->isOnOrder()),
        ]);
    }

    /** 补传装箱单: replaces the draft lines (AsnService::clearDraftLines guards that nothing was received or ordered). */
    public function import(Request $request, Asn $asn, PortalAsnService $service): RedirectResponse
    {
        $this->clientId($request);
        $data = $request->validate(['packing_list' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240']], PortalValidation::messages(), PortalValidation::attributes());

        try {
            $import = $service->replacePackingList($asn, $data['packing_list'], $asn->containers()->value('container_no'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['packing_list' => RuleViolation::display($e)]);
        }

        return redirect()->route('portal.asns.show', $asn)
            ->with('status', __('portal.asns.messages.replaced', ['rows' => $import->row_count, 'errors' => $import->error_count]));
    }

    /** A CSV skeleton with the headers the parser recognises and one example row; UTF-8 BOM so Excel shows the Chinese headers. */
    public function template(Request $request): Response
    {
        $this->clientId($request);
        $example = ['EDW-001', '蓝牙音箱', 'Bluetooth speaker', 'carton', '10', '200', '8.5', '60', '40', '40', '0.96', 'Amazon FBA BWU2', '0400 000 000', '1 Warehouse Rd Moorebank', 'NSW', '2170', 'FBA15ABC123'];
        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::TEMPLATE_HEADERS);
        fputcsv($out, $example);
        rewind($out);
        $csv = "\xEF\xBB\xBF".stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="packing-list-template.csv"',
        ]);
    }

    private function clientId(Request $request): int
    {
        $user = $request->user();
        abort_unless($user?->isClientUser() && $user->client_id !== null, 403, __('portal.messages.client_only'));

        return (int) $user->client_id;
    }
}
