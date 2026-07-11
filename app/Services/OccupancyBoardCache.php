<?php

namespace App\Services;

use App\Services\Cache\VersionedCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Versioned cache for occupancy board (file or redis via CACHE_DRIVER).
 * bump() increments version and publishes HotelLiveUpdated via Reverb.
 */
class OccupancyBoardCache
{
    private const VERSION_KEY = 'occupancy_board_cache_version';

    private const TTL_SECONDS = 90;

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    public static function bump(): void
    {
        if (! Cache::has(self::VERSION_KEY)) {
            Cache::forever(self::VERSION_KEY, 2);
        } else {
            Cache::increment(self::VERSION_KEY);
        }

        app(HotelLivePublisher::class)->publishBoardChanged();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function remember(Carbon $date, array $filters, callable $resolver): array
    {
        $suffix = sprintf('%s:%s', $date->toDateString(), md5(json_encode($filters)));

        return VersionedCache::remember(
            'occupancy_board',
            self::version(),
            $suffix,
            self::TTL_SECONDS,
            $resolver
        );
    }
}
