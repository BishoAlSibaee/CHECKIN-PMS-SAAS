<?php

namespace Tests\Feature\Cache;

use App\Services\OccupancyBoardCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RedisCacheIntegrationTest extends TestCase
{
    public function test_redis_cache_store_is_configured(): void
    {
        $this->assertArrayHasKey('redis', config('cache.stores'));
        $this->assertSame('redis', config('cache.stores.redis.driver'));
        $this->assertArrayHasKey('cache', config('database.redis'));
    }

    public function test_occupancy_board_cache_version_bump_invalidates_remember_payload(): void
    {
        $date = Carbon::parse('2026-08-15');
        $filters = ['floor' => 1];
        $calls = 0;

        $first = OccupancyBoardCache::remember($date, $filters, function () use (&$calls) {
            $calls++;

            return ['rooms' => 1];
        });
        $second = OccupancyBoardCache::remember($date, $filters, function () use (&$calls) {
            $calls++;

            return ['rooms' => 2];
        });

        $this->assertSame(['rooms' => 1], $first);
        $this->assertSame(['rooms' => 1], $second);
        $this->assertSame(1, $calls);

        OccupancyBoardCache::bump();

        $third = OccupancyBoardCache::remember($date, $filters, function () use (&$calls) {
            $calls++;

            return ['rooms' => 3];
        });

        $this->assertSame(['rooms' => 3], $third);
        $this->assertSame(2, $calls);
    }

    public function test_occupancy_board_cache_works_with_redis_when_available(): void
    {
        try {
            Redis::connection()->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis not available: '.$e->getMessage());
        }

        $previousDefault = config('cache.default');
        config(['cache.default' => 'redis']);

        try {
            Cache::flush();

            $before = OccupancyBoardCache::version();
            OccupancyBoardCache::bump();
            $after = OccupancyBoardCache::version();

            $this->assertGreaterThan($before, $after);
        } finally {
            config(['cache.default' => $previousDefault]);
        }
    }
}
