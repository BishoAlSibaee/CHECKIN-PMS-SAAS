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

        Schema::table('accounting_periods', function (Blueprint $table) use ($defaultHotelId) {
            $table->foreignId('hotel_id')->default($defaultHotelId)->after('id')->constrained('hotels');
        });

        DB::table('accounting_periods')->whereNull('hotel_id')->update(['hotel_id' => $defaultHotelId]);

        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->dropUnique(['year', 'month']);
            $table->unique(['hotel_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->dropUnique(['hotel_id', 'year', 'month']);
            $table->unique(['year', 'month']);
            $table->dropConstrainedForeignId('hotel_id');
        });
    }
};
