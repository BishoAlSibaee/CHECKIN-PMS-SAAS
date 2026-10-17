<?php

namespace App\Services\Cache;

use Carbon\Carbon;

class DashboardSummaryCache
{
    private const TTL_SECONDS = 45;

    /**
     * @return array<string, mixed>
     */
    public static function remember(Carbon $date, callable $resolver): array
    {
        $suffix = $date->toDateString();

        return VersionedCache::remember(
            'dashboard_summary',
            HotelCacheVersion::board(),
            $suffix,
            self::TTL_SECONDS,
            $resolver
        );
    }
}
