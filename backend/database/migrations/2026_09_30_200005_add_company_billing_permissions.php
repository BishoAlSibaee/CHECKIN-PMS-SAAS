<?php

use App\Models\Hotel;
use App\Models\Permission;
use App\Models\PermissionCategory;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Corporate billing (deferred payment to a company account) is a
 * management-level decision — granted to admin/manager, same grouping as
 * the other Reservations permissions (group bookings, refunds).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $category = PermissionCategory::firstOrCreate(
            ['name_en' => 'Reservations'],
            ['name_ar' => 'الحجوزات']
        );

        $permissions = [
            ['name' => 'view companies', 'name_ar' => 'عرض الشركات'],
            ['name' => 'manage companies', 'name_ar' => 'إدارة الشركات'],
            ['name' => 'view company ledger', 'name_ar' => 'عرض حساب الشركات'],
            ['name' => 'record company settlement', 'name_ar' => 'تسجيل سداد الشركة'],
            ['name' => 'create company reservations', 'name_ar' => 'إنشاء حجز على حساب شركة'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                ['name_ar' => $permission['name_ar'], 'category_id' => $category->id, 'guard_name' => 'api']
            );
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $names = array_column($permissions, 'name');

        try {
            foreach (Hotel::pluck('id') as $hotelId) {
                $registrar->setPermissionsTeamId($hotelId);

                Role::where('name', 'admin')->where('guard_name', 'api')->first()
                    ?->givePermissionTo($names);

                Role::where('name', 'manager')->where('guard_name', 'api')->first()
                    ?->givePermissionTo($names);
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', [
            'view companies', 'manage companies', 'view company ledger',
            'record company settlement', 'create company reservations',
        ])->delete();
    }
};
