{{-- A22 client report mail: plain HTML tables (mail clients ignore stylesheets), client view only — no cost / margin columns exist in $columns. --}}
<h1>{{ __('reports.mail.title', ['client' => $client->name, 'frequency' => __('reports.mail.frequencies.'.$frequency)]) }}</h1>
<p>{{ __('reports.mail.intro', ['from' => $period->from->toDateString(), 'to' => $period->to->toDateString()]) }}</p>

@foreach ($tables as $table)
    <h2>{{ __('reports.tables.'.$table) }}</h2>
    @if ($report[$table] === [])
        <p>{{ __('reports.empty') }}</p>
    @else
        <table border="1" cellpadding="4" cellspacing="0">
            <thead>
                <tr>
                    @foreach ($columns[$table] as $key => $type)
                        <th align="{{ in_array($type, \App\Modules\Reports\Services\ReportFormat::NUMERIC_TYPES, true) ? 'right' : 'left' }}">{{ __('reports.columns.'.$key) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($report[$table] as $row)
                    <tr>
                        @foreach ($columns[$table] as $key => $type)
                            <td align="{{ in_array($type, \App\Modules\Reports\Services\ReportFormat::NUMERIC_TYPES, true) ? 'right' : 'left' }}">{{ \App\Modules\Reports\Services\ReportFormat::html($type, $row[$key] ?? null) }}</td>
                        @endforeach
                    </tr>
                @endforeach
                @if ($totals[$table] ?? null)
                    <tr>
                        @foreach ($columns[$table] as $key => $type)
                            <th align="{{ in_array($type, \App\Modules\Reports\Services\ReportFormat::NUMERIC_TYPES, true) ? 'right' : 'left' }}">{{ \App\Modules\Reports\Services\ReportFormat::html($type, $totals[$table][$key] ?? null) }}</th>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
    @endif
@endforeach

<p><small>{{ __('reports.mail.footer') }}</small></p>
