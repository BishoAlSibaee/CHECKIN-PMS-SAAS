<?php

use App\Models\Hotel;
use App\Models\Permission;
use App\Models\PermissionCategory;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * "manage channex reviews" — viewing guest reviews across OTA platforms and
 * replying to them is a reputation-management decision, not front-desk work,
 * so it's granted to admin/manager only (not receptionist), same grouping
 * as the other Channex permissions.
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
            ['name' => 'manage channex reviews'],
            ['name_ar' => 'إدارة تقييمات منصات الحجز', 'category_id' => $category->id, 'guard_name' => 'api']
        );

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            foreach (Hotel::pluck('id') as $hotelId) {
                $registrar->setPermissionsTeamId($hotelId);

                Role::where('name', 'admin')->where('guard_name', 'api')->first()
                    ?->givePermissionTo(['manage channex reviews']);

                Role::where('name', 'manager')->where('guard_name', 'api')->first()
                    ?->givePermissionTo(['manage channex reviews']);
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'manage channex reviews')->delete();
    }
};
