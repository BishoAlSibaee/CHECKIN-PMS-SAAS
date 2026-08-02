<?php

namespace App\Services\Cache;

/**
 * Central hooks for cache invalidation tied to domain mutations.
 */
class CacheInvalidator
{
    public static function propertyStructureChanged(): void
    {
        ReferenceDataCache::bumpDomain('buildings');
        ReferenceDataCache::bumpDomain('floors');
    }

    public static function roomTypesChanged(): void
    {
        ReferenceDataCache::bumpDomain('room_types');
    }

    public static function refundPoliciesChanged(): void
    {
        ReferenceDataCache::bumpDomain('refund_policies');
    }

    public static function penaltiesChanged(): void
    {
        ReferenceDataCache::bumpDomain('penalties');
    }

    public static function departmentsChanged(): void
    {
        ReferenceDataCache::bumpDomain('departments');
    }

    public static function jobTitlesChanged(): void
    {
        ReferenceDataCache::bumpDomain('job_titles');
    }

    public static function rbacChanged(): void
    {
        ReferenceDataCache::bumpDomain('roles');
        ReferenceDataCache::bumpDomain('permissions');
    }

    public static function financeChanged(): void
    {
        HotelCacheVersion::bumpFinance();
    }
}
