<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Carrier;
use App\Modules\Transport\Http\TransportValidation;
use App\Modules\Transport\Models\CarrierInvoice;
use App\Modules\Transport\Services\CarrierInvoiceService;
use App\Support\Auth\RequiredRoles;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Response as ResponseFacade;

class CarrierInvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeInternal($request);

        return view('transport::carrier-invoices.index', [
            'invoices' => CarrierInvoice::query()
                ->with('carrier')
                ->withCount([
                    'lines',
                    'lines as difference_count' => fn ($query) => $query->where('matched', false),
                ])
                ->withSum('lines', 'billed_cents')
                ->latest('period_to')
                ->latest('id')
                ->paginate(25),
            'carriers' => Carrier::query()->where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, CarrierInvoiceService $invoices): RedirectResponse
    {
        $this->authorizeInternal($request);
        $data = $request->validate([
            'carrier_id' => ['required', 'integer', 'exists:carriers,id'],
            'invoice_no' => ['required', 'string', 'max:255'],
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'total_cents' => ['required', 'integer', 'min:0'],
            'statement' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ], TransportValidation::messages(), TransportValidation::attributes());

        try {
            $invoice = $invoices->import(
                (int) $data['carrier_id'],
                $data['invoice_no'],
                $data['period_from'],
                $data['period_to'],
                (int) $data['total_cents'],
                $data['statement'],
                $request->user()?->id,
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['statement' => $exception->getMessage()]);
        }

        return redirect()->route('transport.carrier-invoices.show', $invoice)
            ->with('status', __('transport.reconciliation.imported'));
    }

    public function show(Request $request, CarrierInvoice $carrierInvoice): View
    {
        $this->authorizeInternal($request);
        $invoice = $carrierInvoice->load(['carrier', 'document', 'lines.shipment']);
        $lineTotalCents = (int) $invoice->lines->sum('billed_cents');

        return view('transport::carrier-invoices.show', [
            'invoice' => $invoice,
            'lineTotalCents' => $lineTotalCents,
            'totalVarianceCents' => $invoice->total_cents - $lineTotalCents,
        ]);
    }

    public function exportDifferences(Request $request, CarrierInvoice $carrierInvoice): Response
    {
        $this->authorizeInternal($request);
        $lineTotalCents = (int) $carrierInvoice->lines()->sum('billed_cents');
        $carrierInvoice->load(['lines' => fn ($query) => $query->where('matched', false)->with('shipment')]);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [
            __('transport.reconciliation.invoice_no'),
            __('transport.reconciliation.tracking_number'),
            __('transport.reconciliation.shipment'),
            __('transport.reconciliation.billed'),
            __('transport.reconciliation.expected'),
            __('transport.reconciliation.variance'),
            __('transport.reconciliation.note'),
        ]);
        foreach ($carrierInvoice->lines as $line) {
            fputcsv($handle, [
                $carrierInvoice->invoice_no,
                $line->tracking_number,
                $line->shipment?->shipment_no,
                $line->billed_cents,
                $line->expected_cents,
                $line->variance_cents,
                $line->note,
            ]);
        }
        if ($lineTotalCents !== $carrierInvoice->total_cents) {
            fputcsv($handle, [
                $carrierInvoice->invoice_no,
                __('transport.reconciliation.invoice_total_row'),
                null,
                $carrierInvoice->total_cents,
                $lineTotalCents,
                $carrierInvoice->total_cents - $lineTotalCents,
                __('transport.reconciliation.invoice_total_mismatch'),
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return ResponseFacade::make("\xEF\xBB\xBF".$csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="carrier-invoice-'.$carrierInvoice->id.'-differences.csv"',
        ]);
    }

    private function authorizeInternal(Request $request): void
    {
        RequiredRoles::requireAny(['admin', 'transport_operator', 'finance']);
    }
}
