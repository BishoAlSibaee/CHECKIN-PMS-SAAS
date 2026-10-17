<?php

use App\Models\Hotel;
use App\Models\Permission;
use App\Models\PermissionCategory;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds the "manage night audit" permission (view/trigger the nightly
 * reconciliation) and grants it to every existing hotel's "admin" role.
 * New hotels get it automatically via HotelRoleProvisioningService, which
 * grants the admin role every permission that exists at creation time.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $category = PermissionCategory::firstOrCreate(
            ['name_en' => 'Finance & Revenue'],
            ['name_ar' => 'المالية والإيرادات']
        );

        Permission::updateOrCreate(
            ['name' => 'manage night audit'],
            ['name_ar' => 'إدارة التدقيق الليلي', 'category_id' => $category->id, 'guard_name' => 'api']
        );

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            foreach (Hotel::pluck('id') as $hotelId) {
                $registrar->setPermissionsTeamId($hotelId);
                Role::where('name', 'admin')->where('guard_name', 'api')->first()?->givePermissionTo('manage night audit');
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'manage night audit')->delete();
    }
};
