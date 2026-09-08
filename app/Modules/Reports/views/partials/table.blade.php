{{-- One report table. $columns (key => type), $rows (list of assoc rows), $totals (assoc or null). Formatting via ReportFormat, labels via lang/zh/reports.php. --}}
@if ($rows === [])
    <p class="text-muted">{{ __('reports.empty') }}</p>
@else
    <div class="overflow-auto">
        <table class="dense">
            <thead>
                <tr>
                    @foreach ($columns as $key => $type)
                        <th @class(['num' => in_array($type, \App\Modules\Reports\Services\ReportFormat::NUMERIC_TYPES, true)])>{{ __('reports.columns.'.$key) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($columns as $key => $type)
                            <td @class(['num' => in_array($type, \App\Modules\Reports\Services\ReportFormat::NUMERIC_TYPES, true)])>{{ \App\Modules\Reports\Services\ReportFormat::html($type, $row[$key] ?? null) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            @if ($totals)
                <tfoot>
                    <tr>
                        @foreach ($columns as $key => $type)
                            <th @class(['num' => in_array($type, \App\Modules\Reports\Services\ReportFormat::NUMERIC_TYPES, true)])>{{ \App\Modules\Reports\Services\ReportFormat::html($type, $totals[$key] ?? null) }}</th>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
@endif
