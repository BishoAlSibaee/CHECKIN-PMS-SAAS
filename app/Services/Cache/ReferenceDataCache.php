<?php

namespace App\Services\Cache;

use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache for static reference data (buildings, floors, room types).
 */
class ReferenceDataCache
{
    private const TTL_SECONDS = 3600;

    private const VERSION_PREFIX = 'ref_data_version_';

    public static function remember(string $domain, string $suffix, callable $resolver): mixed
    {
        $version = self::version($domain);
        $key = sprintf('ref:%s:v%d:%s', $domain, $version, $suffix);

        return Cache::remember($key, self::TTL_SECONDS, $resolver);
    }

    public static function bumpDomain(string $domain): void
    {
        $versionKey = self::VERSION_PREFIX.$domain;

        if (! Cache::has($versionKey)) {
            Cache::forever($versionKey, 2);
        } else {
            Cache::increment($versionKey);
        }
    }

    public static function version(string $domain): int
    {
        return (int) Cache::get(self::VERSION_PREFIX.$domain, 1);
    }
}
