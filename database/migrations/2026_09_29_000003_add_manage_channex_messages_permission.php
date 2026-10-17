<?php

use App\Models\Hotel;
use App\Models\Permission;
use App\Models\PermissionCategory;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * "manage channex messages" — guest chat threads from OTAs (Booking.com,
 * Expedia, Airbnb) can include pre-booking Airbnb inquiries, so replying is
 * a guest-relations decision, not pure front-desk work. Granted to
 * admin/manager only, same grouping as the other Channex permissions.
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
            ['name' => 'manage channex messages'],
            ['name_ar' => 'إدارة رسائل منصات الحجز', 'category_id' => $category->id, 'guard_name' => 'api']
        );

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            foreach (Hotel::pluck('id') as $hotelId) {
                $registrar->setPermissionsTeamId($hotelId);

                Role::where('name', 'admin')->where('guard_name', 'api')->first()
                    ?->givePermissionTo(['manage channex messages']);

                Role::where('name', 'manager')->where('guard_name', 'api')->first()
                    ?->givePermissionTo(['manage channex messages']);
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'manage channex messages')->delete();
    }
};
