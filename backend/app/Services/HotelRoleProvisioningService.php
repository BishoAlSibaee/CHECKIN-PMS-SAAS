<?php

namespace App\Services;

use App\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every hotel (branch) has its own independent set of roles under Spatie's
 * "teams" feature (team_foreign_key=hotel_id) — a brand-new hotel starts with
 * zero roles, meaning nobody could be assigned anything in it. This seeds the
 * same standard role set database/seeders/PermissionSeeder.php originally
 * created for the first hotel, scoped to a new one, so a hotel is immediately
 * usable (has an "admin" role to assign) the moment it's created.
 */
class HotelRoleProvisioningService
{
    public function provisionDefaultRoles(int $hotelId): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($hotelId);

            $adminRole = $this->role($hotelId, 'admin', 'مدير النظام', 'Full system access');
            $adminRole->syncPermissions(Permission::all());

            $this->role($hotelId, 'receptionist', 'موظف استقبال', 'Front desk access')
                ->syncPermissions([
                    'view buildings', 'view floors', 'view suites', 'view rooms', 'view room types',
                    'view reservations', 'create reservations', 'update reservations', 'cancel reservations',
                    'view earnings', 'view payments', 'view reports', 'export reports',
                    'view clients', 'manage clients', 'manage client notes',
                    'manage peak days', 'manage peak months',
                    'manage airbnb requests',
                    'view pos charges', 'create pos charges',
                ]);

            $this->role($hotelId, 'manager', 'مدير', 'Management access')
                ->syncPermissions([
                    'view buildings', 'view floors', 'view suites', 'view rooms', 'view room types',
                    'manage rooms', 'manage pricing plans',
                    'view reservations', 'create reservations', 'update reservations', 'cancel reservations', 'manage refunds',
                    'view earnings', 'view revenue', 'view payments',
                    'view reports', 'view financial reports', 'export reports',
                    'view clients', 'manage clients', 'manage client notes',
                    'view users', 'manage peak days', 'manage peak months',
                    'manage airbnb requests', 'manage channex reviews', 'manage channex messages',
                    'view group bookings', 'create group bookings', 'cancel group bookings',
                    'view companies', 'manage companies', 'view company ledger',
                    'record company settlement', 'create company reservations',
                    'view pos charges', 'create pos charges', 'void pos charges',
                    'view pos categories', 'manage pos categories',
                ]);

            $this->role($hotelId, 'accountant', 'محاسب', 'Accounting and financial reports')
                ->syncPermissions([
                    'view reports', 'view financial reports', 'view accounting reports', 'export reports',
                    'view revenue', 'view earnings', 'view payments',
                    'manage chart of accounts', 'manage journal entries', 'close accounting period',
                    'view clients',
                ]);

            $this->role($hotelId, 'kiosk', 'جهاز الخدمة الذاتية', 'Self-service kiosk device access')
                ->syncPermissions([
                    'view reservations', 'create reservations', 'update reservations',
                    'view clients', 'manage clients',
                ]);
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }

    /**
     * Bypasses Role::create()'s own built-in "does this already exist"
     * pre-check entirely — Spatie's teams global scope treats a role row
     * with hotel_id=NULL as matching every team, so a single orphaned
     * legacy row (predating multi-hotel support) blocks provisioning for
     * EVERY new hotel forever. updateOrCreate() with hotel_id spelled out
     * explicitly in both the match and the insert sidesteps that scope
     * entirely and is immune to any such stale row.
     */
    private function role(int $hotelId, string $name, string $nameAr, string $description): Role
    {
        return Role::withoutGlobalScopes()->updateOrCreate(
            ['hotel_id' => $hotelId, 'name' => $name, 'guard_name' => 'api'],
            ['name_ar' => $nameAr, 'is_system' => true, 'description' => $description]
        );
    }
}
