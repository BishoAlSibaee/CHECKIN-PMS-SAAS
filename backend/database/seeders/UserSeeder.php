<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * @deprecated Use AdminSeeder directly. Kept for backward compatibility.
     */
    public function run(): void
    {
        $this->call(AdminSeeder::class);
    }
}
