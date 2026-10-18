<?php

namespace Tests\Feature\Cache;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RedisHealthCommandTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    public function test_redis_health_command_is_registered(): void
    {
        $this->assertArrayHasKey('redis:health', Artisan::all());
    }
}
