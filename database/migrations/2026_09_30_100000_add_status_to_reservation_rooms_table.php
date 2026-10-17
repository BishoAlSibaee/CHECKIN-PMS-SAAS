<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0 = active, 1 = cancelled — see ReservationRoom model constants. Default 0
 * means every existing row is implicitly "active"; no backfill needed since
 * per-room cancellation never existed before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_rooms', function (Blueprint $table) {
            $table->unsignedTinyInteger('status')->default(0)->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_rooms', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
