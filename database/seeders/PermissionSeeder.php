<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\PermissionCategory;
use Spatie\Permission\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Clear cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Define Categories
        $categories = [
            'Infrastructure' => ['en' => 'Infrastructure', 'ar' => 'البنية التحتية'],
            'Room Management' => ['en' => 'Room Management', 'ar' => 'إدارة الغرف'],
            'Settings' => ['en' => 'Settings', 'ar' => 'الإعدادات'],
            'Users & Permissions' => ['en' => 'Users & Permissions', 'ar' => 'المستخدمين والصلاحيات'],
            'Reservations' => ['en' => 'Reservations', 'ar' => 'الحجوزات'],
            'Finance' => ['en' => 'Finance & Revenue', 'ar' => 'المالية والإيرادات'],
            'Clients' => ['en' => 'Clients', 'ar' => 'العملاء'],
        ];

        $categoryModels = [];
        foreach ($categories as $key => $names) {
            $categoryModels[$key] = PermissionCategory::updateOrCreate(
                ['name_en' => $names['en']],
                ['name_ar' => $names['ar']]
            );
        }

        // Define Permissions
        $permissions = [
            // Infrastructure
            ['name' => 'view buildings', 'name_ar' => 'عرض المباني', 'category' => 'Infrastructure'],
            ['name' => 'manage buildings', 'name_ar' => 'إدارة المباني', 'category' => 'Infrastructure'],
            ['name' => 'view floors', 'name_ar' => 'عرض الطوابق', 'category' => 'Infrastructure'],
            ['name' => 'manage floors', 'name_ar' => 'إدارة الطوابق', 'category' => 'Infrastructure'],
            ['name' => 'view suites', 'name_ar' => 'عرض الأجنحة', 'category' => 'Infrastructure'],
            ['name' => 'manage suites', 'name_ar' => 'إدارة الأجنحة', 'category' => 'Infrastructure'],

            // Room Management
            ['name' => 'view rooms', 'name_ar' => 'عرض الغرف', 'category' => 'Room Management'],
            ['name' => 'manage rooms', 'name_ar' => 'إدارة الغرف', 'category' => 'Room Management'],
            ['name' => 'view room types', 'name_ar' => 'عرض أنواع الغرف', 'category' => 'Room Management'],
            ['name' => 'manage room types', 'name_ar' => 'إدارة أنواع الغرف', 'category' => 'Room Management'],
            ['name' => 'manage pricing plans', 'name_ar' => 'إدارة خطط التسعير', 'category' => 'Room Management'],

            // Settings
            ['name' => 'manage facilities', 'name_ar' => 'إدارة المرافق', 'category' => 'Settings'],
            ['name' => 'manage features', 'name_ar' => 'إدارة المميزات', 'category' => 'Settings'],
            ['name' => 'manage stay reasons', 'name_ar' => 'إدارة أسباب الإقامة', 'category' => 'Settings'],
            ['name' => 'manage discounts', 'name_ar' => 'إدارة الخصومات', 'category' => 'Settings'],
            ['name' => 'manage taxes', 'name_ar' => 'إدارة الضرائب', 'category' => 'Settings'],
            ['name' => 'manage job titles', 'name_ar' => 'إدارة المسميات الوظيفية', 'category' => 'Settings'],
            ['name' => 'manage departments', 'name_ar' => 'إدارة الأقسام', 'category' => 'Settings'],
            ['name' => 'manage penalties', 'name_ar' => 'إدارة الجزاءات', 'category' => 'Settings'],
            ['name' => 'manage reservation sources', 'name_ar' => 'إدارة مصادر الحجز', 'category' => 'Settings'],
            ['name' => 'manage peak days', 'name_ar' => 'إدارة أيام الذروة', 'category' => 'Settings'],
            ['name' => 'manage peak months', 'name_ar' => 'إدارة أشهر الذروة', 'category' => 'Settings'],
            ['name' => 'manage refund policies', 'name_ar' => 'إدارة سياسات الاسترجاع', 'category' => 'Settings'],

            // Users & Permissions
            ['name' => 'view users', 'name_ar' => 'عرض المستخدمين', 'category' => 'Users & Permissions'],
            ['name' => 'manage users', 'name_ar' => 'إدارة المستخدمين', 'category' => 'Users & Permissions'],
            ['name' => 'manage roles', 'name_ar' => 'إدارة الأدوار', 'category' => 'Users & Permissions'],
            ['name' => 'manage permissions', 'name_ar' => 'إدارة الصلاحيات', 'category' => 'Users & Permissions'],

            // Reservations
            ['name' => 'view reservations', 'name_ar' => 'عرض الحجوزات', 'category' => 'Reservations'],
            ['name' => 'create reservations', 'name_ar' => 'إنشاء الحجوزات', 'category' => 'Reservations'],
            ['name' => 'update reservations', 'name_ar' => 'تحديث الحجوزات', 'category' => 'Reservations'],
            ['name' => 'cancel reservations', 'name_ar' => 'إلغاء الحجوزات', 'category' => 'Reservations'],
            ['name' => 'manage refunds', 'name_ar' => 'إدارة المرتجعات', 'category' => 'Reservations'],

            // Finance
            ['name' => 'view earnings', 'name_ar' => 'عرض الأرباح', 'category' => 'Finance'],
            ['name' => 'view revenue', 'name_ar' => 'عرض الإيرادات', 'category' => 'Finance'],
            ['name' => 'view payments', 'name_ar' => 'عرض المدفوعات', 'category' => 'Finance'],
            ['name' => 'view reports', 'name_ar' => 'عرض التقارير', 'category' => 'Finance'],
            ['name' => 'view financial reports', 'name_ar' => 'عرض التقارير المالية', 'category' => 'Finance'],
            ['name' => 'view accounting reports', 'name_ar' => 'عرض التقارير المحاسبية', 'category' => 'Finance'],
            ['name' => 'export reports', 'name_ar' => 'تصدير التقارير', 'category' => 'Finance'],
            ['name' => 'manage chart of accounts', 'name_ar' => 'إدارة دليل الحسابات', 'category' => 'Finance'],
            ['name' => 'manage journal entries', 'name_ar' => 'إدارة قيود اليومية', 'category' => 'Finance'],
            ['name' => 'close accounting period', 'name_ar' => 'إقفال الفترة المحاسبية', 'category' => 'Finance'],

            // Clients
            ['name' => 'view clients', 'name_ar' => 'عرض العملاء', 'category' => 'Clients'],
            ['name' => 'manage clients', 'name_ar' => 'إدارة العملاء', 'category' => 'Clients'],
            ['name' => 'manage client notes', 'name_ar' => 'إدارة ملاحظات العملاء', 'category' => 'Clients'],
            ['name' => 'manage guest classifications', 'name_ar' => 'إدارة تصنيفات الضيوف', 'category' => 'Clients'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                [
                    'name_ar' => $permission['name_ar'],
                    'category_id' => $categoryModels[$permission['category']]->id,
                    'guard_name' => 'api'
                ]
            );
        }

        // Create Roles and Assign Permissions
        $adminRole = Role::updateOrCreate(
            ['name' => 'admin', 'guard_name' => 'api'],
            ['name_ar' => 'مدير النظام', 'is_system' => true, 'description' => 'Full system access']
        );
        $adminRole->syncPermissions(Permission::all());

        $receptionistRole = Role::updateOrCreate(
            ['name' => 'receptionist', 'guard_name' => 'api'],
            ['name_ar' => 'موظف استقبال', 'is_system' => true, 'description' => 'Front desk access']
        );
        $receptionistRole->syncPermissions([
            'view buildings', 'view floors', 'view suites', 'view rooms', 'view room types',
            'view reservations', 'create reservations', 'update reservations', 'cancel reservations',
            'view earnings', 'view payments', 'view reports', 'export reports',
            'view clients', 'manage clients', 'manage client notes',
            'manage peak days', 'manage peak months',
        ]);

        $managerRole = Role::updateOrCreate(
            ['name' => 'manager', 'guard_name' => 'api'],
            ['name_ar' => 'مدير', 'is_system' => true, 'description' => 'Management access']
        );
        $managerRole->syncPermissions([
            'view buildings', 'view floors', 'view suites', 'view rooms', 'view room types',
            'manage rooms', 'manage pricing plans',
            'view reservations', 'create reservations', 'update reservations', 'cancel reservations', 'manage refunds',
            'view earnings', 'view revenue', 'view payments',
            'view reports', 'view financial reports', 'export reports',
            'view clients', 'manage clients', 'manage client notes',
            'view users', 'manage peak days', 'manage peak months',
        ]);

        $accountantRole = Role::updateOrCreate(
            ['name' => 'accountant', 'guard_name' => 'api'],
            ['name_ar' => 'محاسب', 'is_system' => true, 'description' => 'Accounting and financial reports']
        );
        $accountantRole->syncPermissions([
            'view reports', 'view financial reports', 'view accounting reports', 'export reports',
            'view revenue', 'view earnings', 'view payments',
            'manage chart of accounts', 'manage journal entries', 'close accounting period',
            'view clients',
        ]);

        $kioskRole = Role::updateOrCreate(
            ['name' => 'kiosk', 'guard_name' => 'api'],
            ['name_ar' => 'جهاز الخدمة الذاتية', 'is_system' => true, 'description' => 'Self-service kiosk device access']
        );
        $kioskRole->syncPermissions([
            'view reservations', 'create reservations', 'update reservations',
            'view clients', 'manage clients',
        ]);
    }
}
