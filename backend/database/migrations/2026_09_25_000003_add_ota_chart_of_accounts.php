<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['code' => '1150', 'name_en' => 'OTA Platforms Clearing', 'name_ar' => 'تسوية منصات الحجز', 'type' => 'asset'],
            ['code' => '5030', 'name_en' => 'OTA Commission Expense', 'name_ar' => 'عمولات منصات الحجز', 'type' => 'expense'],
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
        DB::table('chart_of_accounts')->whereIn('code', ['1150', '5030'])->delete();
    }
};
