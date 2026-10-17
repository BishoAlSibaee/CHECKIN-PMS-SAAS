<?php

use App\Models\Hotel;
use App\Models\Permission;
use App\Models\PermissionCategory;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds "manage ota allocation" (release rooms to OTA channels) and
 * "view platform ledger" (per-platform receivable/payable statement),
 * granted to every existing hotel's "admin" role. New hotels get both
 * automatically via HotelRoleProvisioningService.
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
            ['name' => 'manage ota allocation'],
            ['name_ar' => 'إدارة تخصيص الغرف لمنصات الحجز', 'category_id' => $category->id, 'guard_name' => 'api']
        );
        Permission::updateOrCreate(
            ['name' => 'view platform ledger'],
            ['name_ar' => 'عرض حساب منصات الحجز', 'category_id' => $category->id, 'guard_name' => 'api']
        );

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            foreach (Hotel::pluck('id') as $hotelId) {
                $registrar->setPermissionsTeamId($hotelId);
                $adminRole = Role::where('name', 'admin')->where('guard_name', 'api')->first();
                $adminRole?->givePermissionTo(['manage ota allocation', 'view platform ledger']);
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', ['manage ota allocation', 'view platform ledger'])->delete();
    }
};
