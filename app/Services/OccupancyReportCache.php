<?php

namespace App\Services;

use App\Services\Cache\VersionedCache;
use Carbon\Carbon;

/**
 * Versioned cache for occupancy report payloads (file or redis via CACHE_STORE/CACHE_DRIVER).
 * Keys include board_version so bump() invalidates stale occupancy reports.
 */
class OccupancyReportCache
{
    private const TTL_SECONDS = 120;

    public static function remember(
        Carbon $start,
        Carbon $end,
        ?Carbon $compareStart,
        ?Carbon $compareEnd,
        callable $resolver
    ): array {
        $suffix = sprintf(
            '%s:%s:%s:%s',
            $start->toDateString(),
            $end->toDateString(),
            $compareStart?->toDateString() ?? 'none',
            $compareEnd?->toDateString() ?? 'none'
        );

        return VersionedCache::remember(
            'occupancy_report',
            OccupancyBoardCache::version(),
            $suffix,
            self::TTL_SECONDS,
            $resolver
        );
    }
}
