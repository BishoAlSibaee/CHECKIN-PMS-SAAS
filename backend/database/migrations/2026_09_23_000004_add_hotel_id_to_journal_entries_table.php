<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $defaultHotelId = DB::table('hotels')->orderBy('id')->value('id');

        Schema::table('journal_entries', function (Blueprint $table) use ($defaultHotelId) {
            $table->foreignId('hotel_id')->default($defaultHotelId)->after('id')->constrained('hotels');
        });

        DB::table('journal_entries')->whereNull('hotel_id')->update(['hotel_id' => $defaultHotelId]);
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hotel_id');
        });
    }
};
