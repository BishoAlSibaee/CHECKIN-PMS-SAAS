<?php

namespace App\Services\Cache;

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

        return Cache::remember($key, $ttlSeconds, $resolver);
    }
}
