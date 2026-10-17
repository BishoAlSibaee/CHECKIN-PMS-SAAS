<?php

use App\Models\Hotel;
use App\Models\Permission;
use App\Models\PermissionCategory;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds "manage airbnb requests" (view/accept/decline pending Airbnb Request-
 * to-Book items — front-desk work, granted to admin/manager/receptionist)
 * and "manage airbnb settings" (configure the hotel's accept/manual-review/
 * decline policy — an admin-only decision). Granted to every existing
 * hotel's roles here; new hotels get both automatically via
 * HotelRoleProvisioningService.
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
            ['name' => 'manage airbnb requests'],
            ['name_ar' => 'إدارة طلبات الحجز من Airbnb', 'category_id' => $category->id, 'guard_name' => 'api']
        );
        Permission::updateOrCreate(
            ['name' => 'manage airbnb settings'],
            ['name_ar' => 'إعدادات سياسة طلبات Airbnb', 'category_id' => $category->id, 'guard_name' => 'api']
        );

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            foreach (Hotel::pluck('id') as $hotelId) {
                $registrar->setPermissionsTeamId($hotelId);

                Role::where('name', 'admin')->where('guard_name', 'api')->first()
                    ?->givePermissionTo(['manage airbnb requests', 'manage airbnb settings']);

                Role::where('name', 'manager')->where('guard_name', 'api')->first()
                    ?->givePermissionTo(['manage airbnb requests']);

                Role::where('name', 'receptionist')->where('guard_name', 'api')->first()
                    ?->givePermissionTo(['manage airbnb requests']);
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', ['manage airbnb requests', 'manage airbnb settings'])->delete();
    }
};
