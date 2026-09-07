<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\OrderEnums;
use App\Modules\Orders\Services\OrderImportService;
use App\Modules\Platform\Models\Job;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Illuminate\Validation\Rule;

final class OrderImportController extends Controller
{
    public function index(): View
    {
        $this->authorizeImport();

        return view('orders::imports.index', [
            'imports' => OrderImport::query()->with(['client', 'creator'])->latest('id')->paginate(25),
        ]);
    }

    public function create(): View
    {
        $this->authorizeImport();

        return view('orders::imports.create', [
            'clients' => Client::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'jobs' => Job::query()->where('operational_status', '!=', 'cancelled')->latest('id')->get(['id', 'job_no', 'client_id']),
            'serviceLevels' => OrderEnums::SERVICE_LEVELS,
        ]);
    }

    public function preview(Request $request, OrderImportService $imports): RedirectResponse
    {
        $this->authorizeImport();
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('status', 'active')],
            'job_id' => ['required', 'integer', Rule::exists('jobs', 'id')],
            'requested_date' => ['required', 'date'],
            'service_level' => ['required', Rule::in(OrderEnums::SERVICE_LEVELS)],
            'manifest' => ['required', 'file', 'max:10240', function ($attribute, $value, $fail) {
                if (! in_array(mb_strtolower($value->getClientOriginalExtension()), ['csv', 'xlsx'], true)) {
                    $fail(__('orders.imports.errors.unsupported_file'));
                }
            }],
        ]);

        abort_unless(Job::query()->whereKey($data['job_id'])->where('client_id', $data['client_id'])->exists(), 422, __('orders.validation.job_client_mismatch'));
        $import = $imports->preview($data['manifest'], [
            'client_id' => (int) $data['client_id'],
            'job_id' => (int) $data['job_id'],
            'requested_date' => $data['requested_date'],
            'service_level' => $data['service_level'],
        ], $request->user()?->id);

        return redirect()->route('orders.imports.show', $import);
    }

    public function show(OrderImport $import): View
    {
        $this->authorizeImport();

        return view('orders::imports.show', ['import' => $import->load(['client', 'creator'])]);
    }

    public function confirm(Request $request, OrderImport $import, OrderImportService $imports): RedirectResponse
    {
        $this->authorizeImport();
        $data = $request->validate([
            'groups' => ['nullable', 'array'],
            'groups.*' => ['string', 'size:64'],
            'save_addresses' => ['nullable', 'array'],
            'save_addresses.*' => ['string', 'size:64'],
        ]);

        $import = $imports->confirm($import, $data['groups'] ?? [], $data['save_addresses'] ?? [], $request->user()?->id);

        return redirect()->route('orders.imports.show', $import)->with('status', __('orders.imports.messages.completed', [
            'success' => count($import->errors['result']['created'] ?? []),
            'failed' => $import->errors['result']['failed_rows'] ?? 0,
        ]));
    }

    public function errors(OrderImport $import): Response
    {
        $this->authorizeImport();
        $rows = $import->errors['issues'] ?? [];
        foreach ($import->errors['groups'] ?? [] as $group) {
            if (! in_array($group['status'], ['blocked', 'duplicate', 'asn_match'], true)) {
                continue;
            }
            foreach ($group['row_numbers'] as $row) {
                $rows[] = ['row' => $row, 'column' => 'consignment_mark', 'message' => $group['message']];
            }
        }

        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, [__('orders.imports.csv.row'), __('orders.imports.csv.column'), __('orders.imports.csv.message')]);
        foreach ($rows as $row) {
            fputcsv($handle, [$row['row'], $row['column'], $row['message']]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return ResponseFacade::make("\xEF\xBB\xBF".$csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="order-import-'.$import->id.'-errors.csv"',
        ]);
    }

    private function authorizeImport(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['admin', 'customer_service', 'dispatcher']), 403);
    }
}
