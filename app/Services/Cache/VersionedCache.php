<?php

namespace App\Services\Cache;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class VersionedCache
{
    /**
     * @return mixed
     */
    public static function remember(
        string $prefix,
        int $version,
        string $suffix,
        int $ttlSeconds,
        callable $resolver
    ) {
        $key = sprintf('%s:v%d:%s', $prefix, $version, $suffix);

        return self::rememberKey($key, $ttlSeconds, $resolver);
    }

    /**
     * Stampede-safe remember with hit/miss metrics.
     * Uses a short Cache::lock around rebuild; falls back to Cache::remember if locks are unavailable.
     *
     * @return mixed
     */
    public static function rememberKey(string $key, int $ttlSeconds, callable $resolver)
    {
        if (Cache::has($key)) {
            CacheMetrics::hit();

            return Cache::get($key);
        }

        $lockKey = 'stampede:'.sha1($key);

        try {
            return Cache::lock($lockKey, 10)->block(5, function () use ($key, $ttlSeconds, $resolver) {
                if (Cache::has($key)) {
                    CacheMetrics::hit();

                    return Cache::get($key);
                }

                CacheMetrics::miss();
                $value = $resolver();
                Cache::put($key, $value, $ttlSeconds);

                return $value;
            });
        } catch (LockTimeoutException) {
            if (Cache::has($key)) {
                CacheMetrics::hit();

                return Cache::get($key);
            }

            CacheMetrics::miss();

            return Cache::remember($key, $ttlSeconds, $resolver);
        } catch (\Throwable) {
            CacheMetrics::miss();

            return Cache::remember($key, $ttlSeconds, $resolver);
        }
    }
}
