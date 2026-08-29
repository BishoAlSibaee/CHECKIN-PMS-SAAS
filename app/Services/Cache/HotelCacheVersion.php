<?php

namespace App\Services\Cache;

use App\Services\OccupancyBoardCache;
use Illuminate\Support\Facades\Cache;

/**
 * Shared board version used to invalidate versioned operational caches.
 */
class HotelCacheVersion
{
    private const FINANCE_KEY = 'hotel:finance_version';

    public static function board(): int
    {
        return OccupancyBoardCache::version();
    }

    public static function finance(): int
    {
        return (int) Cache::get(self::FINANCE_KEY, 1);
    }

    public static function bumpFinance(): int
    {
        if (! Cache::has(self::FINANCE_KEY)) {
            Cache::forever(self::FINANCE_KEY, 1);
        }

        return (int) Cache::increment(self::FINANCE_KEY);
    }
}
