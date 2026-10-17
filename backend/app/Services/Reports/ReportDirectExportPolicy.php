<?php

namespace App\Services\Reports;

/**
 * Direct browser downloads (PDF / Excel / CSV) are capped.
 * Larger result sets must use email export or tighter filters.
 */
class ReportDirectExportPolicy
{
    public static function maxDirectDownloadRows(): int
    {
        $configured = config('reports.max_direct_download_rows');
        if ($configured === null || $configured === '') {
            $configured = config('reports.preview_limit', 40);
        }

        return max(1, (int) $configured);
    }

    public static function allowsDirectDownload(int $rowCount): bool
    {
        return $rowCount <= self::maxDirectDownloadRows();
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function assertAllowsDirectDownload(int $rowCount): void
    {
        if (self::allowsDirectDownload($rowCount)) {
            return;
        }

        $max = self::maxDirectDownloadRows();

        throw new \InvalidArgumentException(
            "This report has {$rowCount} rows, which exceeds the direct download limit of {$max}. "
            .'Narrow the date range / filters, or export via email.'
        );
    }

    /**
     * @return array{max_direct_download_rows: int, direct_download_allowed: bool, requires_email_or_filters: bool}
     */
    public static function metaForRowCount(int $rowCount): array
    {
        $max = self::maxDirectDownloadRows();
        $allowed = $rowCount <= $max;

        return [
            'max_direct_download_rows' => $max,
            'direct_download_allowed' => $allowed,
            'requires_email_or_filters' => ! $allowed,
        ];
    }
}
