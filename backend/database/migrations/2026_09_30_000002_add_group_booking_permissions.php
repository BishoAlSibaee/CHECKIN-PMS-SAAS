<?php

use App\Models\Hotel;
use App\Models\Permission;
use App\Models\PermissionCategory;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Group/event bookings are a management-level feature (organizing multiple
 * guests' reservations under one event, applying a group discount) — granted
 * to admin/manager, same grouping as the other Reservations permissions.
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
            ['name' => 'view group bookings', 'name_ar' => 'عرض الحجوزات الجماعية'],
            ['name' => 'create group bookings', 'name_ar' => 'إنشاء الحجوزات الجماعية'],
            ['name' => 'cancel group bookings', 'name_ar' => 'إلغاء الحجوزات الجماعية'],
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
        Permission::whereIn('name', ['view group bookings', 'create group bookings', 'cancel group bookings'])->delete();
    }
};
