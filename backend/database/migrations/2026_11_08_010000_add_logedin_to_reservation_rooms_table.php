<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-room check-in/check-out tracking — see ReservationRoom model constants.
 * `reservations.logedin` stays as an aggregate (in-house if any active room
 * is), recomputed by ReservationRoomStatusService::recalculateAggregateLogedin().
 *
 * New columns default to "not in house" — without a backfill, every room on
 * an already-in-house reservation would look available the moment the
 * per-room-aware availability checks ship, since they stop consulting the
 * reservation-level flag. Backfill every active room of a currently
 * confirmed + in-house reservation to logedin=1 using that reservation's own
 * login_time, so existing stays aren't silently exposed as bookable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_rooms', function (Blueprint $table) {
            $table->unsignedTinyInteger('logedin')->default(0)->after('status');
            $table->timestamp('login_time')->nullable()->after('logedin');
            $table->timestamp('logout_time')->nullable()->after('login_time');
        });

        DB::statement(<<<'SQL'
            UPDATE reservation_rooms rr
            INNER JOIN reservations r ON r.id = rr.reservation_id
            SET rr.logedin = 1, rr.login_time = r.login_time
            WHERE rr.status = 0
              AND r.reservation_status = 1
              AND r.logedin = 1
        SQL);
    }

    public function down(): void
    {
        Schema::table('reservation_rooms', function (Blueprint $table) {
            $table->dropColumn(['logedin', 'login_time', 'logout_time']);
        });
    }
};
