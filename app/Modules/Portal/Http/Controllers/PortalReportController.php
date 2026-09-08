<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Reports\Services\ReportCsv;
use App\Modules\Reports\Services\ReportPeriod;
use App\Modules\Reports\Services\ReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A21 客户视角 for client users (PLT-3 / §2.5 #1, #5): the signed-in client's own figures, never cost or margin. The client id
 * comes from the user — never from the request — and ReportService adds the explicit client predicate to every query.
 */
final class PortalReportController extends Controller
{
    public function index(Request $request, ReportService $reports): View
    {
        $client = $this->client($request);
        $period = ReportPeriod::fromInput($request->validate(ReportPeriod::rules()));
        $report = $reports->client($client->id, $period);

        return view('reports::client', [
            'period' => $period,
            'client' => $client,
            'clients' => collect(),
            'report' => $report,
            'tables' => ReportService::TABLES,
            'columns' => collect(ReportService::TABLES)->mapWithKeys(fn ($t) => [$t => $reports->columns($t, true)])->all(),
            'totals' => collect(ReportService::TABLES)->mapWithKeys(fn ($t) => [$t => $reports->totals($t, $report[$t], true)])->all(),
            'portal' => true,
            'filterAction' => route('portal.reports'),
            'exportUrl' => fn (string $table) => route('portal.reports.export', ['table' => $table, 'from' => $period->from->toDateString(), 'to' => $period->to->toDateString()]),
        ]);
    }

    public function export(Request $request, string $table, ReportService $reports, ReportCsv $csv): StreamedResponse
    {
        $client = $this->client($request);
        abort_unless(in_array($table, ReportService::TABLES, true), 404);
        $period = ReportPeriod::fromInput($request->validate(ReportPeriod::rules()));
        $rows = $reports->client($client->id, $period)[$table];
        $content = $csv->render($reports->columns($table, true), $rows, $reports->totals($table, $rows, true));

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $csv->filename($table, $period, $client->code), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function client(Request $request): Client
    {
        $user = $request->user();
        abort_unless($user?->isClientUser() && $user->client_id !== null, 403, __('portal.messages.client_only'));

        return Client::query()->findOrFail((int) $user->client_id); // client-scoped: resolves only to the user's own client
    }
}
