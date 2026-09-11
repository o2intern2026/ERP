<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Http\PortalValidation;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnImport;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 预报入库 for the client, read-only (CHANGE_REQUESTS #116 → #117): the ASNs staff opened for the client's goods — from its orders
 * (从订单生成预报单) or by hand — with progress, goods lines, packing-list parse results and the 入库单 PDFs. Isolation is the
 * data-layer client scope on `asns`; another client's ASN is a 404. Clients do not create ASNs (lead decision 2026-09-11).
 */
final class PortalAsnController extends Controller
{
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

    public function show(Request $request, Asn $asn): View
    {
        $this->clientId($request);
        $asn->load(['warehouse', 'job', 'containers', 'lines.container', 'goodsReceipts.pdfDocument', 'clientConfirmedBy']);

        return view('portal::asns.show', [
            'asn' => $asn,
            'imports' => AsnImport::query()->where('asn_id', $asn->id)->orderByDesc('id')->get(),
        ]);
    }

    private function clientId(Request $request): int
    {
        $user = $request->user();
        abort_unless($user?->isClientUser() && $user->client_id !== null, 403, __('portal.messages.client_only'));

        return (int) $user->client_id;
    }
}
