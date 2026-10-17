<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->constrained('hotels')->nullOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('name_en');
            $table->string('name_ar');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $accounts = DB::table('chart_of_accounts')->whereIn('code', ['4030', '4040', '4050', '4060'])->pluck('id', 'code');

        $categories = [
            ['code' => '4030', 'name_en' => 'Restaurant', 'name_ar' => 'مطعم'],
            ['code' => '4040', 'name_en' => 'Cafe', 'name_ar' => 'كافيه'],
            ['code' => '4050', 'name_en' => 'Laundry', 'name_ar' => 'مغسلة'],
            ['code' => '4060', 'name_en' => 'Minibar', 'name_ar' => 'ميني بار'],
        ];

        foreach ($categories as $category) {
            if (!isset($accounts[$category['code']])) {
                continue;
            }

            DB::table('pos_categories')->insert([
                'hotel_id' => null,
                'account_id' => $accounts[$category['code']],
                'name_en' => $category['name_en'],
                'name_ar' => $category['name_ar'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_categories');
    }
};
