<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\InteractsWithApi;
use Tests\Support\InteractsWithReservationFixtures;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use InteractsWithApi;
    use InteractsWithReservationFixtures;

    private static bool $isolatedTestSchemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        // userWithApiPermissions() caches its User across calls as a
        // micro-optimization — but the cache is a STATIC property, so it
        // survives across test METHODS within the same PHPUnit process. A
        // test using DatabaseTransactions rolls its DB writes back when it
        // finishes, including this cached user's row and hotel attachment;
        // without resetting the cache here, the NEXT test that hits the
        // same cache key gets back a User object whose underlying row no
        // longer exists, failing with "This account is not assigned to any
        // hotel." Cheap enough to just always clear it per test regardless
        // of whether this particular test uses transactions.
        self::$cachedPermissionUser = null;
        self::$cachedPermissionKey = '';

        if (! app()->environment('testing') || ! \App\Support\ReservationTestDataGuard::isIsolatedDatabase()) {
            return;
        }

        if (self::$isolatedTestSchemaReady) {
            return;
        }

        $needsSchema = ! \Illuminate\Support\Facades\Schema::hasTable('reservations')
            || ! \Illuminate\Support\Facades\Schema::hasTable('zatca_invoices')
            || (\Illuminate\Support\Facades\Schema::hasTable('zatca_invoices')
                && ! \Illuminate\Support\Facades\Schema::hasColumn('zatca_invoices', 'qr_code'));

        if ($needsSchema) {
            $this->artisan('migrate', ['--force' => true, '--no-interaction' => true]);
        }
        self::$isolatedTestSchemaReady = true;
    }

    private static ?\App\Models\User $cachedPermissionUser = null;

    private static string $cachedPermissionKey = '';

    /** Dedicated API test user — never mutate production admin (001). */
    protected function apiTestUser(): \App\Models\User
    {
        $user = \App\Models\User::firstOrCreate(
            ['job_number' => 'TEST-API'],
            [
                'name' => 'API Test User',
                'email' => 'test-api@hotel.test',
                'mobile' => '0599999998',
                'active' => 1,
                'password' => bcrypt('test-api-password'),
            ]
        );

        // Every request goes through ResolveCurrentHotel, which 403s a user
        // with no hotel access at all — attach the test user to whichever
        // hotel the multi-branch migrations seeded as default.
        $defaultHotelId = \App\Models\Hotel::query()->orderBy('id')->value('id');
        if ($defaultHotelId !== null) {
            $user->hotels()->syncWithoutDetaching([$defaultHotelId]);
        }

        return $user;
    }

    protected function userWithApiPermissions(array $extra = []): \App\Models\User
    {
        $user = $this->apiTestUser();

        $names = array_values(array_unique(array_merge([
            'view reservations', 'create reservations', 'update reservations', 'cancel reservations',
            'view earnings', 'view revenue', 'view payments', 'manage refunds',
            'view buildings', 'view rooms', 'view clients',
        ], $extra)));
        sort($names);
        $cacheKey = implode('|', $names);

        if (self::$cachedPermissionUser !== null && self::$cachedPermissionKey === $cacheKey) {
            return self::$cachedPermissionUser;
        }

        foreach ($names as $name) {
            \Spatie\Permission\Models\Permission::findOrCreate($name, 'api');
        }

        // syncPermissions() stamps the pivot's team_foreign_key (hotel_id)
        // from whatever team id is currently set — outside a request there is
        // none, so set it to the user's hotel before writing the pivot rows.
        $defaultHotelId = \App\Models\Hotel::query()->orderBy('id')->value('id');
        app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($defaultHotelId);
        $user->syncPermissions($names);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        self::$cachedPermissionUser = $user;
        self::$cachedPermissionKey = $cacheKey;

        return $user;
    }
}
