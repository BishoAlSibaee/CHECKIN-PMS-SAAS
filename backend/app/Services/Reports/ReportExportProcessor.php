<?php

namespace App\Services\Reports;

use App\Mail\ReportReadyMail;
use App\Models\ReportExport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class ReportExportProcessor
{
    public function __construct(
        private ReportQueryService $reportQueryService,
        private ReportExcelWriter $excelWriter,
        private ReportPdfWriter $pdfWriter,
    ) {
    }

    public function process(ReportExport $export): void
    {
        if ($export->status !== ReportExport::STATUS_PENDING) {
            return;
        }

        $export->update([
            'status' => ReportExport::STATUS_PROCESSING,
            'error_message' => null,
        ]);

        try {
            @set_time_limit(300);
            @ini_set('memory_limit', '512M');

            $params = $this->buildParams($export);
            $data = $this->reportQueryService->run($export->slug, $params);

            $format = $export->file_format ?: ReportExport::FORMAT_EXCEL;
            $extension = $format === ReportExport::FORMAT_PDF ? 'pdf' : 'xlsx';
            $relativePath = 'reports/export-' . $export->id . '-' . $export->slug . '.' . $extension;

            if ($format === ReportExport::FORMAT_PDF) {
                $rowCount = $this->pdfWriter->write($relativePath, $export, $data);
            } else {
                $rowCount = $this->excelWriter->write(
                    $relativePath,
                    $data['columns'] ?? [],
                    $data['rows'] ?? []
                );
            }

            $export->update([
                'status' => ReportExport::STATUS_READY,
                'file_path' => $relativePath,
                'row_count' => $rowCount,
                'processed_at' => now(),
                'expires_at' => now()->addDays((int) config('reports.export_retention_days', 7)),
            ]);

            Mail::to($export->recipient_email)->send(new ReportReadyMail($export->fresh()));
        } catch (\Throwable $e) {
            $export->update([
                'status' => ReportExport::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            if ($export->file_path) {
                Storage::disk('local')->delete($export->file_path);
            }
        }
    }

    /**
     * Build a report file for immediate browser download (capped by ReportDirectExportPolicy).
     *
     * @param  array<string, string|null>  $params
     * @return array{content: string, filename: string, mime: string}
     *
     * @throws \InvalidArgumentException when the result set is too large for direct download
     */
    public function buildDownload(string $slug, string $format, array $params): array
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $data = $this->reportQueryService->run($slug, $params);
        ReportDirectExportPolicy::assertAllowsDirectDownload(count($data['rows'] ?? []));
        $end = $params['end_date'] ?? now()->toDateString();
        $extension = $format === ReportExport::FORMAT_PDF ? 'pdf' : 'xlsx';
        $filename = $slug.'-'.$end.'.'.$extension;

        if ($format === ReportExport::FORMAT_PDF) {
            return [
                'content' => $this->pdfWriter->render($slug, $data),
                'filename' => $filename,
                'mime' => 'application/pdf',
            ];
        }

        return [
            'content' => $this->excelWriter->render($data['columns'] ?? [], $data['rows'] ?? []),
            'filename' => $filename,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    public function cleanupExpired(): int
    {
        $deleted = 0;

        ReportExport::query()
            ->where('expires_at', '<', now())
            ->whereNotNull('file_path')
            ->each(function (ReportExport $export) use (&$deleted) {
                if ($export->file_path && Storage::disk('local')->exists($export->file_path)) {
                    Storage::disk('local')->delete($export->file_path);
                }
                $export->update(['file_path' => null]);
                $deleted++;
            });

        return $deleted;
    }

    public static function slugRequiresAccountingPermission(string $slug): bool
    {
        return ReportCatalog::slugRequiresAccountingPermission($slug);
    }

    public static function isValidSlug(string $slug): bool
    {
        return in_array($slug, ReportCatalog::allSlugs(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function allSlugs(): array
    {
        return ReportCatalog::allSlugs();
    }

    /**
     * @return array<string, string|null>
     */
    private function buildParams(ReportExport $export): array
    {
        return array_filter([
            'start_date' => $export->start_date?->toDateString(),
            'end_date' => $export->end_date?->toDateString(),
            'compare_start_date' => $export->compare_start_date?->toDateString(),
            'compare_end_date' => $export->compare_end_date?->toDateString(),
        ], fn ($value) => $value !== null && $value !== '');
    }
}
