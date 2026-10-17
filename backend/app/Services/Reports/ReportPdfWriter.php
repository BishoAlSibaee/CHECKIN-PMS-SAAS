<?php

namespace App\Services\Reports;

use App\Models\ReportExport;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

class ReportPdfWriter
{
    /**
     * @param  array{columns?: array, rows?: array, summary?: array, meta_lines?: array}  $data
     */
    public function write(string $relativePath, ReportExport $export, array $data): int
    {
        Storage::disk('local')->makeDirectory('reports');
        Storage::disk('local')->put($relativePath, $this->render($export->slug, $data, $export));

        return count($data['rows'] ?? []);
    }

    /**
     * @param  array{columns?: array, rows?: array, summary?: array, meta_lines?: array}  $data
     */
    public function render(string $slug, array $data, ?ReportExport $export = null): string
    {
        $columns = $data['columns'] ?? [];
        $rows = $data['rows'] ?? [];
        $landscape = count($columns) > 6 || count($rows) > 40;
        $rowsPerPage = max(1, (int) config('reports.pdf_rows_per_page', 20));

        $html = view('reports.export-pdf', [
            'export' => $export,
            'title' => str_replace('-', ' ', $slug),
            'periodLabel' => $this->periodLabel($slug, $export, $data),
            'columns' => $columns,
            'rowPages' => array_chunk($rows, $rowsPerPage),
            'summary' => $data['summary'] ?? [],
            'metaLines' => $data['meta_lines'] ?? [],
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', $landscape ? 'landscape' : 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @param  array{columns?: array, rows?: array, summary?: array, meta_lines?: array}  $data
     */
    private function periodLabel(string $slug, ?ReportExport $export, array $data): string
    {
        if ($export) {
            return $this->periodLabelFromExport($export);
        }

        $meta = $data['meta_lines'] ?? [];
        foreach ($meta as $line) {
            if (is_string($line) && str_starts_with($line, 'Period:')) {
                return trim(substr($line, strlen('Period:')));
            }
        }

        return $slug === 'room-board' ? '—' : '—';
    }

    private function periodLabelFromExport(ReportExport $export): string
    {
        if ($export->slug === 'room-board') {
            return $export->end_date?->toDateString() ?? '—';
        }

        $start = $export->start_date?->toDateString() ?? '—';
        $end = $export->end_date?->toDateString() ?? '—';

        return "{$start} → {$end}";
    }
}
