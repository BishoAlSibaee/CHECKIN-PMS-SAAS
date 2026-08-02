<?php

namespace App\Console\Commands;

use App\Services\Cache\CacheMetrics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class RedisHealthCommand extends Command
{
    protected $signature = 'redis:health';

    protected $description = 'Verify Redis connectivity, cache store, and lightweight hit/miss metrics';

    public function handle(): int
    {
        $store = (string) config('cache.default');
        $client = (string) config('database.redis.client');
        $this->info("Cache store: {$store}");
        $this->info("Redis client: {$client}");
        $this->line('REDIS_DB='.config('database.redis.default.database').' (queues/locks)');
        $this->line('REDIS_CACHE_DB='.config('database.redis.cache.database').' (cache)');

        if ($store !== 'redis') {
            $this->warn('Cache store is not redis — shared worker cache and redis:health ping are limited.');
        }

        try {
            $pong = Redis::connection('default')->ping();
            $this->info('Redis default connection: '.((string) $pong ?: 'OK'));
        } catch (\Throwable $e) {
            $this->error('Redis default connection failed: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            Redis::connection('cache')->ping();
            $probeKey = 'redis_health_probe:'.uniqid('', true);
            Cache::store($store === 'redis' ? 'redis' : $store)->put($probeKey, 1, 10);
            $ok = Cache::store($store === 'redis' ? 'redis' : $store)->pull($probeKey) === 1;
            if (! $ok) {
                $this->error('Cache write/read probe failed.');

                return self::FAILURE;
            }
            $this->info('Cache store probe: OK');
        } catch (\Throwable $e) {
            $this->error('Cache connection failed: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            $info = Redis::connection('default')->info('memory');
            $used = $info['used_memory_human'] ?? ($info['Memory']['used_memory_human'] ?? 'n/a');
            $max = $info['maxmemory_human'] ?? ($info['Memory']['maxmemory_human'] ?? 'n/a');
            $policy = $info['maxmemory_policy'] ?? ($info['Memory']['maxmemory_policy'] ?? 'n/a');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['used_memory', $used],
                    ['maxmemory', $max],
                    ['maxmemory_policy', $policy],
                ]
            );
        } catch (\Throwable $e) {
            $this->warn('Could not read INFO memory: '.$e->getMessage());
        }

        $metrics = CacheMetrics::snapshot();
        $this->table(
            ['Cache metrics', 'Count'],
            [
                ['hits', (string) $metrics['hits']],
                ['misses', (string) $metrics['misses']],
                ['hit_ratio', $metrics['hit_ratio']],
            ]
        );

        $this->info('redis:health passed.');

        return self::SUCCESS;
    }
}
