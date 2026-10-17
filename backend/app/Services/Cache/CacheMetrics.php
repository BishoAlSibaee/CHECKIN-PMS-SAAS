<?php

namespace App\Services\Cache;

use Illuminate\Support\Facades\Cache;

/**
 * Lightweight process-visible hit/miss counters (stored in the default cache).
 * Does not change financial report caching policy.
 */
class CacheMetrics
{
    private const HITS_KEY = 'cache_metrics:hits';

    private const MISSES_KEY = 'cache_metrics:misses';

    public static function hit(): void
    {
        self::bump(self::HITS_KEY);
    }

    public static function miss(): void
    {
        self::bump(self::MISSES_KEY);
    }

    /**
     * @return array{hits: int, misses: int, hit_ratio: float|string}
     */
    public static function snapshot(): array
    {
        $hits = (int) Cache::get(self::HITS_KEY, 0);
        $misses = (int) Cache::get(self::MISSES_KEY, 0);
        $total = $hits + $misses;

        return [
            'hits' => $hits,
            'misses' => $misses,
            'hit_ratio' => $total > 0 ? round($hits / $total, 4) : 'n/a',
        ];
    }

    private static function bump(string $key): void
    {
        try {
            if (! Cache::has($key)) {
                Cache::forever($key, 0);
            }
            Cache::increment($key);
        } catch (\Throwable) {
            // Metrics must never break request paths.
        }
    }
}
