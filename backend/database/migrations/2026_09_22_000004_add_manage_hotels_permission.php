<?php

use App\Models\Permission;
use App\Models\PermissionCategory;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds the "manage hotels" permission (branch/hotel administration) and grants
 * it to the existing "admin" role. Permissions themselves aren't team-scoped
 * in Spatie (only role/user assignment is), but granting it to a role IS —
 * done here explicitly for the one hotel that exists today (id=1, created in
 * create_hotels_table). New hotels get their own admin role with this already
 * included via HotelRoleProvisioningService.
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
            ['name' => 'manage hotels'],
            ['name_ar' => 'إدارة الفروع', 'category_id' => $category->id, 'guard_name' => 'api']
        );

        $defaultHotelId = \DB::table('hotels')->orderBy('id')->value('id');
        if ($defaultHotelId) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($defaultHotelId);
            $adminRole = Role::where('name', 'admin')->where('guard_name', 'api')->first();
            $adminRole?->givePermissionTo('manage hotels');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'manage hotels')->delete();
    }
};
