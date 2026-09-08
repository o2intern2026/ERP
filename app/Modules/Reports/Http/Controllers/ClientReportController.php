<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Reports\Services\ReportCsv;
use App\Modules\Reports\Services\ReportPeriod;
use App\Modules\Reports\Services\ReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A21 客户视角 for staff: pick a client and see exactly what that client sees (no cost / margin columns). Client users get the
 * same tables at /portal/reports (PortalReportController) because ClientScope confines them to /portal/** (CHANGE_REQUESTS #52).
 */
final class ClientReportController extends Controller
{
    public const ROLES = ['admin', 'finance', 'customer_service', 'dispatcher'];

    public function index(Request $request, ReportService $reports): View
    {
        $this->authorizeStaff($request);
        $data = $request->validate(ReportPeriod::rules() + ['client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')]]);
        $period = ReportPeriod::fromInput($data);
        $client = filled($data['client_id'] ?? null) ? Client::query()->findOrFail((int) $data['client_id']) : null;
        $report = $client ? $reports->client($client->id, $period) : null;

        return view('reports::client', [
            'period' => $period,
            'client' => $client,
            'clients' => Client::query()->orderBy('name')->get(['id', 'code', 'name']),
            'report' => $report,
            'tables' => ReportService::TABLES,
            'columns' => collect(ReportService::TABLES)->mapWithKeys(fn ($t) => [$t => $reports->columns($t, true)])->all(),
            'totals' => $report ? collect(ReportService::TABLES)->mapWithKeys(fn ($t) => [$t => $reports->totals($t, $report[$t], true)])->all() : [],
            'portal' => false,
            'filterAction' => route('reports.client'),
            'exportUrl' => fn (string $table) => route('reports.client.export', ['table' => $table, 'client_id' => $client?->id, 'from' => $period->from->toDateString(), 'to' => $period->to->toDateString()]),
        ]);
    }

    public function export(Request $request, string $table, ReportService $reports, ReportCsv $csv): StreamedResponse
    {
        $this->authorizeStaff($request);
        abort_unless(in_array($table, ReportService::TABLES, true), 404);
        $data = $request->validate(ReportPeriod::rules() + ['client_id' => ['required', 'integer', Rule::exists('clients', 'id')]]);
        $period = ReportPeriod::fromInput($data);
        $client = Client::query()->findOrFail((int) $data['client_id']);
        $rows = $reports->client($client->id, $period)[$table];
        $content = $csv->render($reports->columns($table, true), $rows, $reports->totals($table, $rows, true));

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $csv->filename($table, $period, $client->code), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(self::ROLES), 403);
    }
}
