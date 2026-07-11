<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportCatalogCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReportCatalogCacheTest extends TestCase
{
    public function test_catalog_cache_returns_same_payload_without_recomputing(): void
    {
        Cache::flush();

        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return ReportCatalog::all();
        };

        $first = ReportCatalogCache::remember($resolver);
        $second = ReportCatalogCache::remember($resolver);

        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
        $this->assertCount(count(ReportCatalog::allSlugs()), $first);
    }

    public function test_catalog_endpoint_uses_cached_metadata(): void
    {
        Cache::flush();

        $user = $this->userWithApiPermissions(['view reports']);

        $first = $this->actingAs($user, 'api')->getJson('/api/users/reports/catalog');
        $second = $this->actingAs($user, 'api')->getJson('/api/users/reports/catalog');

        $first->assertOk();
        $second->assertOk();
        $this->assertSame(
            $first->json('data.reports'),
            $second->json('data.reports')
        );
        $this->assertCount(count(ReportCatalog::allSlugs()), $first->json('data.reports'));
    }
}
