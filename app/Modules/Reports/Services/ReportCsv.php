<?php

namespace App\Modules\Reports\Services;

/** CSV rendering of one report table (UTF-8 with BOM so Excel opens the Chinese headers correctly). */
final class ReportCsv
{
    /**
     * @param  array<string, string>  $columns  column key => type
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $totals
     */
    public function render(array $columns, array $rows, ?array $totals = null): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn (string $key) => __('reports.columns.'.$key), array_keys($columns)));
        foreach ($rows as $row) {
            fputcsv($out, $this->cells($columns, $row));
        }
        if ($totals !== null) {
            fputcsv($out, $this->cells($columns, $totals));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return (string) $csv;
    }

    public function filename(string $table, ReportPeriod $period, ?string $scope = null): string
    {
        return 'report-'.$table.($scope ? '-'.preg_replace('/[^A-Za-z0-9_-]+/', '', $scope) : '').'-'.$period->slug().'.csv';
    }

    /** @param array<string, string> $columns */
    private function cells(array $columns, array $row): array
    {
        return array_map(fn (string $key, string $type) => ReportFormat::csv($type, $row[$key] ?? null), array_keys($columns), $columns);
    }
}
