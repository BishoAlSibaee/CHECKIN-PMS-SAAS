<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GRMS v1 charges: departments "other" and "room_service" both have no
 * matching revenue account today (only restaurant/cafe/laundry/minibar
 * exist) — owner decided both map onto a single new "Other" category
 * (parking, gym, or any paid service outside the four existing ones; a
 * priced room-service item is rare and also goes here, per description).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('chart_of_accounts')->updateOrInsert(
            ['code' => '4070'],
            [
                'code' => '4070',
                'name_en' => 'Other Revenue',
                'name_ar' => 'إيراد أخرى',
                'type' => 'revenue',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $accountId = DB::table('chart_of_accounts')->where('code', '4070')->value('id');

        DB::table('pos_categories')->updateOrInsert(
            ['hotel_id' => null, 'name_en' => 'Other'],
            [
                'hotel_id' => null,
                'account_id' => $accountId,
                'name_en' => 'Other',
                'name_ar' => 'أخرى',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('pos_categories')->where('hotel_id', null)->where('name_en', 'Other')->delete();
        DB::table('chart_of_accounts')->where('code', '4070')->delete();
    }
};
