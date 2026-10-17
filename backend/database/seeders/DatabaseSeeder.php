<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Fresh install: RBAC, calendar lookups, booking reference lists, admin user.
     *
     * Does NOT seed buildings, rooms, reservations, or demo finance data.
     * For demo dataset: php artisan demo:setup --fresh
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            PeakDayAndMonthSeeder::class,
            EssentialReferenceDataSeeder::class,
            AdminSeeder::class,
        ]);
    }
}
