<?php

namespace Tests\Feature\Tenancy;

use App\Models\Hotel;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers the one-click "onboard a new SaaS customer" flow, and — more
 * importantly — that it is reachable ONLY through the separate
 * "platform_admin" guard (App\Models\PlatformAdmin). No App\Models\User
 * account, no matter which hotel/tenant it belongs to or which permissions
 * it holds, can ever authenticate against that guard at all.
 */
class TenantOnboardingTest extends TestCase
{
    use DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function platformAdmin(): PlatformAdmin
    {
        $suffix = (string) random_int(10000000, 99999999);

        return PlatformAdmin::create([
            'name' => 'Platform Operator '.$suffix,
            'email' => 'platform-admin-test-'.$suffix.'@test.local',
            'password' => bcrypt('x'),
            'is_active' => true,
        ]);
    }

    private function createHotelForNewTenant(string $label): Hotel
    {
        $tenant = Tenant::create([
            'name_ar' => 'مستأجر '.$label,
            'name_en' => 'Tenant '.$label,
            'is_active' => true,
        ]);

        return Hotel::create([
            'tenant_id' => $tenant->id,
            'name_ar' => 'فندق '.$label,
            'name_en' => 'Hotel '.$label,
            'is_active' => true,
        ]);
    }

    /** @param  list<string>  $permissions */
    private function userForHotel(Hotel $hotel, array $permissions): User
    {
        $suffix = (string) random_int(10000000, 99999999);
        $user = User::create([
            'name' => 'Onboarding Test '.$suffix,
            'job_number' => 'ONBOARD-TEST-'.$suffix,
            'email' => 'onboard-test-'.$suffix.'@test.local',
            'mobile' => '05'.$suffix,
            'active' => 1,
            'password' => bcrypt('x'),
        ]);

        $user->hotels()->attach($hotel->id);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($hotel->id);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'api');
        }
        $user->syncRoles([]);
        $user->syncPermissions($permissions);
        $registrar->forgetCachedPermissions();

        return $user;
    }

    public function test_a_platform_admin_can_onboard_a_new_customer(): void
    {
        $operator = $this->platformAdmin();
        $suffix = (string) random_int(10000000, 99999999);

        $response = $this->actingAs($operator, 'platform_admin')
            ->postJson('/api/platform-admin/tenants', [
                'tenant_name_ar' => 'عميل تجريبي',
                'tenant_name_en' => 'Test Customer '.$suffix,
                'hotel_name_ar' => 'فندقهم الأول',
                'hotel_name_en' => 'Their First Hotel '.$suffix,
                'buildings_count' => 1,
                'floors_count' => 4,
                'rooms_per_floor' => 10,
                'subscription_days' => 14,
                'admin_name' => 'New Admin '.$suffix,
                'admin_job_number' => 'NEWADMIN-'.$suffix,
                'admin_email' => 'newadmin-'.$suffix.'@test.local',
                'admin_mobile' => '05'.$suffix,
                'admin_password' => 'a-strong-password',
            ]);

        $this->assertApiSuccess($response);

        $newHotelId = $response->json('data.hotel.id');
        $newTenantId = $response->json('data.tenant.id');
        $this->assertDatabaseHas('hotels', ['id' => $newHotelId, 'tenant_id' => $newTenantId]);
        $this->assertDatabaseHas('users', ['job_number' => 'NEWADMIN-'.$suffix]);
        $this->assertDatabaseHas('hotel_user', ['hotel_id' => $newHotelId]);

        // buildings_count=1, floors_count=4, rooms_per_floor=10 -> 1 building,
        // 4 floors, 40 rooms numbered 101-110/201-210/301-310/401-410, same
        // pattern ClientTrialSeeder uses.
        $buildingId = \DB::table('buildings')->where('hotel_id', $newHotelId)->value('id');
        $this->assertNotNull($buildingId);
        $this->assertSame(4, \DB::table('floors')->where('building_id', $buildingId)->count());
        $this->assertSame(40, \DB::table('rooms')->where('building_id', $buildingId)->count());
        $this->assertDatabaseHas('rooms', ['building_id' => $buildingId, 'number' => '101']);
        $this->assertDatabaseHas('rooms', ['building_id' => $buildingId, 'number' => '410']);

        $this->assertNotNull($response->json('data.tenant.subscription_ends_at'));
        $this->assertDatabaseHas('tenants', [
            'id' => $newTenantId,
            'created_by_platform_admin_id' => $operator->id,
        ]);

        // The new admin can log in (through the main hotel app's own login)
        // and act within their own brand-new tenant immediately.
        $loginResponse = $this->postJson('/api/users/login', [
            'job_number' => 'NEWADMIN-'.$suffix,
            'password' => 'a-strong-password',
        ]);
        $loginResponse->assertOk();
        $loginResponse->assertJsonPath('success', true);
        $this->assertNotEmpty($loginResponse->json('data.token'));
    }

    public function test_onboarding_defaults_currency_to_sar_when_not_specified(): void
    {
        $operator = $this->platformAdmin();
        $suffix = (string) random_int(10000000, 99999999);

        $response = $this->actingAs($operator, 'platform_admin')
            ->postJson('/api/platform-admin/tenants', [
                'tenant_name_ar' => 'عميل بلا عملة محددة',
                'tenant_name_en' => 'No Currency Customer '.$suffix,
                'hotel_name_ar' => 'فندقهم',
                'hotel_name_en' => 'Their Hotel '.$suffix,
                'buildings_count' => 1,
                'floors_count' => 1,
                'rooms_per_floor' => 1,
                'admin_name' => 'Admin '.$suffix,
                'admin_job_number' => 'NOCUR-'.$suffix,
                'admin_email' => 'nocur-'.$suffix.'@test.local',
                'admin_mobile' => '05'.$suffix,
                'admin_password' => 'a-strong-password',
            ]);

        $this->assertApiSuccess($response);
        $this->assertSame('SAR', $response->json('data.tenant.currency'));
        $this->assertDatabaseHas('tenants', ['id' => $response->json('data.tenant.id'), 'currency' => 'SAR']);
    }

    public function test_onboarding_accepts_an_explicit_non_sar_currency(): void
    {
        $operator = $this->platformAdmin();
        $suffix = (string) random_int(10000000, 99999999);

        $response = $this->actingAs($operator, 'platform_admin')
            ->postJson('/api/platform-admin/tenants', [
                'tenant_name_ar' => 'عميل بعملة أخرى',
                'tenant_name_en' => 'USD Customer '.$suffix,
                'hotel_name_ar' => 'فندقهم',
                'hotel_name_en' => 'Their Hotel '.$suffix,
                'buildings_count' => 1,
                'floors_count' => 1,
                'rooms_per_floor' => 1,
                'currency' => 'USD',
                'admin_name' => 'Admin '.$suffix,
                'admin_job_number' => 'USDCUR-'.$suffix,
                'admin_email' => 'usdcur-'.$suffix.'@test.local',
                'admin_mobile' => '05'.$suffix,
                'admin_password' => 'a-strong-password',
            ]);

        $this->assertApiSuccess($response);
        $this->assertSame('USD', $response->json('data.tenant.currency'));
        $this->assertDatabaseHas('tenants', ['id' => $response->json('data.tenant.id'), 'currency' => 'USD']);
    }

    public function test_onboarding_rejects_an_unsupported_currency(): void
    {
        $operator = $this->platformAdmin();
        $suffix = (string) random_int(10000000, 99999999);

        $response = $this->actingAs($operator, 'platform_admin')
            ->postJson('/api/platform-admin/tenants', [
                'tenant_name_ar' => 'محاولة',
                'tenant_name_en' => 'Attempt '.$suffix,
                'hotel_name_ar' => 'محاولة',
                'hotel_name_en' => 'Attempt '.$suffix,
                'buildings_count' => 1,
                'floors_count' => 1,
                'rooms_per_floor' => 1,
                'currency' => 'XYZ',
                'admin_name' => 'Admin '.$suffix,
                'admin_job_number' => 'BADCUR-'.$suffix,
                'admin_email' => 'badcur-'.$suffix.'@test.local',
                'admin_mobile' => '05'.$suffix,
                'admin_password' => 'a-strong-password',
            ]);

        $response->assertStatus(422);
    }

    public function test_a_platform_admin_can_add_a_branch_to_an_existing_tenant(): void
    {
        $operator = $this->platformAdmin();
        $suffix = (string) random_int(10000000, 99999999);

        $onboard = $this->actingAs($operator, 'platform_admin')
            ->postJson('/api/platform-admin/tenants', [
                'tenant_name_ar' => 'عميل متعدد الفروع',
                'tenant_name_en' => 'Multi Branch Customer '.$suffix,
                'hotel_name_ar' => 'الفرع الأول',
                'hotel_name_en' => 'Branch One '.$suffix,
                'buildings_count' => 1,
                'floors_count' => 2,
                'rooms_per_floor' => 5,
                'subscription_days' => null,
                'admin_name' => 'Branch Admin '.$suffix,
                'admin_job_number' => 'BRANCHADMIN-'.$suffix,
                'admin_email' => 'branchadmin-'.$suffix.'@test.local',
                'admin_mobile' => '05'.$suffix,
                'admin_password' => 'a-strong-password',
            ]);

        $tenantId = $onboard->json('data.tenant.id');
        $firstHotelId = $onboard->json('data.hotel.id');

        $response = $this->actingAs($operator, 'platform_admin')
            ->postJson("/api/platform-admin/tenants/{$tenantId}/hotels", [
                'hotel_name_ar' => 'الفرع الثاني',
                'hotel_name_en' => 'Branch Two '.$suffix,
                'buildings_count' => 2,
                'floors_count' => 1,
                'rooms_per_floor' => 3,
            ]);

        $this->assertApiSuccess($response);
        $secondHotelId = $response->json('data.id');
        $this->assertDatabaseHas('hotels', ['id' => $secondHotelId, 'tenant_id' => $tenantId]);

        // buildings_count=2, floors_count=1, rooms_per_floor=3 -> 2 buildings, 3 rooms each.
        $this->assertSame(2, \DB::table('buildings')->where('hotel_id', $secondHotelId)->count());
        $this->assertSame(6, \DB::table('rooms')
            ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
            ->where('buildings.hotel_id', $secondHotelId)
            ->count());

        // The first branch's admin was automatically granted access to the new branch too.
        $this->assertDatabaseHas('hotel_user', [
            'hotel_id' => $secondHotelId,
            'user_id' => User::where('job_number', 'BRANCHADMIN-'.$suffix)->value('id'),
        ]);
    }

    public function test_no_hotel_user_account_can_authenticate_as_a_platform_admin(): void
    {
        // Simulates exactly what HotelRoleProvisioningService does for every
        // new tenant's admin role: grants "manage tenants" too. Must still
        // be refused — this guard only ever recognizes PlatformAdmin models,
        // never App\Models\User, regardless of permissions or which tenant.
        $platformTenantId = (int) Tenant::query()->orderBy('id')->value('id');
        $platformHotel = Hotel::where('tenant_id', $platformTenantId)->firstOrFail();
        $operatorLikeUser = $this->userForHotel($platformHotel, ['manage tenants']);

        $response = $this->actingAs($operatorLikeUser, 'api')
            ->postJson('/api/platform-admin/tenants', [
                'tenant_name_ar' => 'محاولة',
                'tenant_name_en' => 'Attempt',
                'hotel_name_ar' => 'محاولة',
                'hotel_name_en' => 'Attempt',
                'buildings_count' => 1,
                'floors_count' => 1,
                'rooms_per_floor' => 1,
                'admin_name' => 'Attempt',
                'admin_job_number' => 'ATTEMPT-'.uniqid(),
                'admin_email' => 'attempt-'.uniqid().'@test.local',
                'admin_mobile' => '05'.random_int(10000000, 99999999),
                'admin_password' => 'a-strong-password',
            ]);

        $response->assertStatus(401);
    }

    public function test_platform_admin_login_rejects_an_inactive_account(): void
    {
        $admin = $this->platformAdmin();
        $admin->update(['is_active' => false]);

        $response = $this->postJson('/api/platform-admin/login', [
            'email' => $admin->email,
            'password' => 'x',
        ]);

        $response->assertStatus(401);
    }

    public function test_a_platform_admin_can_rename_and_deactivate_a_tenant(): void
    {
        $operator = $this->platformAdmin();
        $hotel = $this->createHotelForNewTenant('Editable'.uniqid());
        $tenant = $hotel->tenant;

        $response = $this->actingAs($operator, 'platform_admin')
            ->putJson("/api/platform-admin/tenants/{$tenant->id}", [
                'name_ar' => 'اسم جديد',
                'name_en' => 'New Name',
                'is_active' => false,
            ]);

        $this->assertApiSuccess($response);
        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name_ar' => 'اسم جديد',
            'name_en' => 'New Name',
            'is_active' => false,
        ]);
    }

    public function test_deactivating_a_tenant_locks_out_its_users_immediately(): void
    {
        $operator = $this->platformAdmin();
        $hotel = $this->createHotelForNewTenant('Deactivate'.uniqid());
        $user = $this->userForHotel($hotel, []);
        $user->update(['password' => bcrypt('a-strong-password')]);

        $loginBefore = $this->postJson('/api/users/login', [
            'job_number' => $user->job_number,
            'password' => 'a-strong-password',
        ]);
        $loginBefore->assertOk();

        $this->actingAs($operator, 'platform_admin')
            ->putJson("/api/platform-admin/tenants/{$hotel->tenant_id}", ['is_active' => false])
            ->assertOk();

        $loginAfter = $this->postJson('/api/users/login', [
            'job_number' => $user->job_number,
            'password' => 'a-strong-password',
        ]);
        $loginAfter->assertStatus(403);
        $this->assertStringContainsString('deactivated', (string) $loginAfter->json('message'));
    }

    public function test_a_platform_admin_can_renew_a_subscription(): void
    {
        $operator = $this->platformAdmin();
        $hotel = $this->createHotelForNewTenant('Renew'.uniqid());
        $tenant = $hotel->tenant;
        $tenant->update(['subscription_ends_at' => now()->addDays(5)]);

        $response = $this->actingAs($operator, 'platform_admin')
            ->postJson("/api/platform-admin/tenants/{$tenant->id}/renew", [
                'extend_days' => 10,
            ]);

        $this->assertApiSuccess($response);
        // Extends from the existing (still-future) end date, not from today —
        // 5 days left + 10 more = 15 days out, not 10.
        $newEnd = \Carbon\Carbon::parse($response->json('data.subscription_ends_at'));
        $this->assertTrue($newEnd->isAfter(now()->addDays(14)));
        $this->assertTrue($newEnd->isBefore(now()->addDays(16)));
    }

    public function test_a_platform_admin_can_grant_a_tenant_unlimited_access(): void
    {
        $operator = $this->platformAdmin();
        $hotel = $this->createHotelForNewTenant('NoExpiry'.uniqid());
        $tenant = $hotel->tenant;
        $tenant->update(['subscription_ends_at' => now()->subDay()]);

        $response = $this->actingAs($operator, 'platform_admin')
            ->postJson("/api/platform-admin/tenants/{$tenant->id}/renew", [
                'no_expiry' => true,
            ]);

        $this->assertApiSuccess($response);
        $this->assertNull($response->json('data.subscription_ends_at'));
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'subscription_ends_at' => null]);
    }

    public function test_a_platform_admin_can_add_a_teammate(): void
    {
        $operator = $this->platformAdmin();

        $response = $this->actingAs($operator, 'platform_admin')
            ->postJson('/api/platform-admin/admins', [
                'name' => 'Second Operator',
                'email' => 'second-operator-'.uniqid().'@test.local',
                'password' => 'a-strong-password',
            ]);

        $this->assertApiSuccess($response);
        $this->assertDatabaseHas('platform_admins', ['name' => 'Second Operator']);

        $list = $this->actingAs($operator, 'platform_admin')->getJson('/api/platform-admin/admins');
        $this->assertApiSuccess($list);
        $this->assertGreaterThanOrEqual(2, count($list->json('data')));
    }
}
