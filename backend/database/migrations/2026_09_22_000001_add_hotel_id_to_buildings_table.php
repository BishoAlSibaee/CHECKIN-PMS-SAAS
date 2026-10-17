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

        Schema::table('buildings', function (Blueprint $table) use ($defaultHotelId) {
            $table->foreignId('hotel_id')->default($defaultHotelId)->after('id')->constrained('hotels');
        });

        // The default() above only affects future inserts on some drivers —
        // make sure every existing row is explicitly backfilled too.
        DB::table('buildings')->whereNull('hotel_id')->update(['hotel_id' => $defaultHotelId]);

        Schema::table('buildings', function (Blueprint $table) {
            $table->dropUnique('buildings_number_unique');
            $table->unique(['hotel_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropUnique(['hotel_id', 'number']);
            $table->string('number')->unique(false)->change();
            $table->unique('number');
            $table->dropConstrainedForeignId('hotel_id');
        });
    }
};
