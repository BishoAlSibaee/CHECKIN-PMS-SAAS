<?php

use App\Models\Hotel;
use App\Models\Permission;
use App\Models\PermissionCategory;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * External messaging-provider subscriptions (Taqnyat SMS balance) and the
 * promotional-campaign drafts that target guest lists. Admin/manager only —
 * this touches third-party credentials and guest contact lists, a higher
 * trust bar than POS charges.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $category = PermissionCategory::firstOrCreate(
            ['name_en' => 'External Subscriptions'],
            ['name_ar' => 'الاشتراكات الخارجية']
        );

        Permission::updateOrCreate(
            ['name' => 'manage external subscriptions'],
            ['name_ar' => 'إدارة الاشتراكات الخارجية', 'category_id' => $category->id, 'guard_name' => 'api']
        );

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            foreach (Hotel::pluck('id') as $hotelId) {
                $registrar->setPermissionsTeamId($hotelId);

                Role::where('name', 'admin')->where('guard_name', 'api')->first()
                    ?->givePermissionTo('manage external subscriptions');

                Role::where('name', 'manager')->where('guard_name', 'api')->first()
                    ?->givePermissionTo('manage external subscriptions');
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'manage external subscriptions')->delete();
    }
};
