<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['code' => '4030', 'name_en' => 'Restaurant Revenue', 'name_ar' => 'إيراد المطعم', 'type' => 'revenue'],
            ['code' => '4040', 'name_en' => 'Cafe Revenue', 'name_ar' => 'إيراد الكافيه', 'type' => 'revenue'],
            ['code' => '4050', 'name_en' => 'Laundry Revenue', 'name_ar' => 'إيراد المغسلة', 'type' => 'revenue'],
            ['code' => '4060', 'name_en' => 'Minibar Revenue', 'name_ar' => 'إيراد الميني بار', 'type' => 'revenue'],
        ];

        foreach ($rows as $row) {
            DB::table('chart_of_accounts')->updateOrInsert(
                ['code' => $row['code']],
                $row + ['active' => true, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')->whereIn('code', ['4030', '4040', '4050', '4060'])->delete();
    }
};
