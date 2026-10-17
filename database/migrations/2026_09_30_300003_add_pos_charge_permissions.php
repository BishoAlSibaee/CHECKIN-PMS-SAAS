<?php

use App\Models\Hotel;
use App\Models\Permission;
use App\Models\PermissionCategory;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * POS charges (restaurant/cafe/laundry/minibar). Receptionist gets the fast
 * entry-level permissions (view/create) since front desk staff are expected
 * to run this screen day to day in small hotels; voiding a charge and
 * managing categories stay admin/manager-only, matching the trust boundary
 * already used for refunds.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $category = PermissionCategory::firstOrCreate(
            ['name_en' => 'Point of Sale'],
            ['name_ar' => 'نقاط البيع']
        );

        $permissions = [
            ['name' => 'view pos charges', 'name_ar' => 'عرض رسوم نقاط البيع'],
            ['name' => 'create pos charges', 'name_ar' => 'إضافة رسوم نقاط البيع'],
            ['name' => 'void pos charges', 'name_ar' => 'إلغاء رسوم نقاط البيع'],
            ['name' => 'view pos categories', 'name_ar' => 'عرض فئات نقاط البيع'],
            ['name' => 'manage pos categories', 'name_ar' => 'إدارة فئات نقاط البيع'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                ['name_ar' => $permission['name_ar'], 'category_id' => $category->id, 'guard_name' => 'api']
            );
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $frontDeskNames = ['view pos charges', 'create pos charges'];
        $managementNames = array_column($permissions, 'name');

        try {
            foreach (Hotel::pluck('id') as $hotelId) {
                $registrar->setPermissionsTeamId($hotelId);

                Role::where('name', 'admin')->where('guard_name', 'api')->first()
                    ?->givePermissionTo($managementNames);

                Role::where('name', 'manager')->where('guard_name', 'api')->first()
                    ?->givePermissionTo($managementNames);

                Role::where('name', 'receptionist')->where('guard_name', 'api')->first()
                    ?->givePermissionTo($frontDeskNames);
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', [
            'view pos charges', 'create pos charges', 'void pos charges',
            'view pos categories', 'manage pos categories',
        ])->delete();
    }
};
