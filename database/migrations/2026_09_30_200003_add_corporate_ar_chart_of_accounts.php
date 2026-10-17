<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('chart_of_accounts')->updateOrInsert(
            ['code' => '1160'],
            ['code' => '1160', 'name_en' => 'Corporate Accounts Receivable', 'name_ar' => 'ذمم الشركات المدينة', 'type' => 'asset', 'active' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')->where('code', '1160')->delete();
    }
};
