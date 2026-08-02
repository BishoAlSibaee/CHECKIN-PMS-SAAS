<?php

namespace Tests\Feature\Cache;

use App\Services\Cache\ReferenceDataCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReferenceDataCacheTest extends TestCase
{
    public function test_reference_cache_invalidates_on_domain_bump(): void
    {
        Cache::flush();

        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return ['value' => $calls];
        };

        $first = ReferenceDataCache::remember('buildings', 'all', $resolver);
        $second = ReferenceDataCache::remember('buildings', 'all', $resolver);

        $this->assertSame(['value' => 1], $first);
        $this->assertSame(['value' => 1], $second);
        $this->assertSame(1, $calls);

        ReferenceDataCache::bumpDomain('buildings');

        $third = ReferenceDataCache::remember('buildings', 'all', $resolver);

        $this->assertSame(['value' => 2], $third);
        $this->assertSame(2, $calls);
    }

    public function test_admin_reference_domains_have_explicit_invalidators(): void
    {
        Cache::flush();

        $domains = [
            'penalties' => 'penaltiesChanged',
            'departments' => 'departmentsChanged',
            'job_titles' => 'jobTitlesChanged',
        ];

        foreach ($domains as $domain => $method) {
            $firstVersion = ReferenceDataCache::version($domain);

            \App\Services\Cache\CacheInvalidator::{$method}();

            $this->assertGreaterThan($firstVersion, ReferenceDataCache::version($domain));
        }
    }

    public function test_rbac_invalidator_bumps_roles_and_permissions_domains(): void
    {
        Cache::flush();

        $rolesBefore = ReferenceDataCache::version('roles');
        $permissionsBefore = ReferenceDataCache::version('permissions');

        \App\Services\Cache\CacheInvalidator::rbacChanged();

        $this->assertGreaterThan($rolesBefore, ReferenceDataCache::version('roles'));
        $this->assertGreaterThan($permissionsBefore, ReferenceDataCache::version('permissions'));
    }
}
