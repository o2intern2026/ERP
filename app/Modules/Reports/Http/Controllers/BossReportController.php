<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Services\ReportCsv;
use App\Modules\Reports\Services\ReportPeriod;
use App\Modules\Reports\Services\ReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A21 老板视角: every client, volumes, on-time rate, revenue / cost / margin. Admin and Finance only (ERP_PLAN §2.5 #5). */
final class BossReportController extends Controller
{
    public const ROLES = ['admin', 'finance'];

    public function index(Request $request, ReportService $reports): View
    {
        $this->authorizeBoss($request);
        $period = ReportPeriod::fromInput($request->validate(ReportPeriod::rules()));
        $report = $reports->boss($period);

        return view('reports::index', [
            'period' => $period,
            'report' => $report,
            'tables' => ReportService::TABLES,
            'columns' => collect(ReportService::TABLES)->mapWithKeys(fn ($t) => [$t => $reports->columns($t, false)])->all(),
            'totals' => collect(ReportService::TABLES)->mapWithKeys(fn ($t) => [$t => $reports->totals($t, $report[$t], false)])->all(),
            'exportUrl' => fn (string $table) => route('reports.export', ['table' => $table, 'from' => $period->from->toDateString(), 'to' => $period->to->toDateString()]),
        ]);
    }

    public function export(Request $request, string $table, ReportService $reports, ReportCsv $csv): StreamedResponse
    {
        $this->authorizeBoss($request);
        abort_unless(in_array($table, ReportService::TABLES, true), 404);
        $period = ReportPeriod::fromInput($request->validate(ReportPeriod::rules()));
        $rows = $reports->boss($period)[$table];
        $content = $csv->render($reports->columns($table, false), $rows, $reports->totals($table, $rows, false));

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $csv->filename($table, $period), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeBoss(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(self::ROLES), 403);
    }
}
