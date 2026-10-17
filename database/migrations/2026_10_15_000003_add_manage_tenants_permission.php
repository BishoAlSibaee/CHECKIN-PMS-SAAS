<?php

use App\Models\Permission;
use App\Models\PermissionCategory;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds the "manage tenants" permission (onboarding new SaaS customers) and
 * grants it to the existing default tenant's "admin" role only.
 *
 * Unlike every other permission, this one must NEVER be meaningfully usable
 * by any tenant other than the platform owner's own (tenant id 1) — but
 * HotelRoleProvisioningService::provisionDefaultRoles() grants every NEW
 * tenant's admin role ALL permissions via Permission::all(), so the
 * permission bit alone cannot be the only gate. TenantsController also
 * hard-checks the caller's current tenant is the platform's own, same
 * "lowest id = the platform/default entity" convention used by
 * HotelResolverService::defaultHotelId() and AdminSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $category = PermissionCategory::firstOrCreate(
            ['name_en' => 'Users & Permissions'],
            ['name_ar' => 'المستخدمين والصلاحيات']
        );

        Permission::updateOrCreate(
            ['name' => 'manage tenants'],
            ['name_ar' => 'إدارة المستأجرين', 'category_id' => $category->id, 'guard_name' => 'api']
        );

        $defaultHotelId = \DB::table('hotels')->orderBy('id')->value('id');
        if ($defaultHotelId) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($defaultHotelId);
            $adminRole = Role::where('name', 'admin')->where('guard_name', 'api')->first();
            $adminRole?->givePermissionTo('manage tenants');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'manage tenants')->delete();
    }
};
