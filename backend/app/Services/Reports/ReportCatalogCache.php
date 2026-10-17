<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache for static report catalog metadata (slug list).
 * Does not cache report payloads — financial reports stay uncached in ReportQueryService.
 */
class ReportCatalogCache
{
    private const KEY = 'report_catalog_v1';

    private const TTL_SECONDS = 300;

    /**
     * @return array<int, array{slug: string, category: string, canonical_source: string}>
     */
    public static function remember(callable $resolver): array
    {
        return Cache::remember(self::KEY, self::TTL_SECONDS, $resolver);
    }
}
