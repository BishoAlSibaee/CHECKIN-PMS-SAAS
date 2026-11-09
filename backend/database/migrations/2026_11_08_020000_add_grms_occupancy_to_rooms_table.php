<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GRMS v1 contract's room.occupancy_changed (section 6) — a physical
 * presence-sensor signal, independent of our own reservation-based
 * occupancy and housekeeping roomStatus. Purely a display field (a room
 * board icon); nothing in our own availability/check-in logic reads it.
 * Nullable because most hotels have no occupancy sensors at all — null
 * means "GRMS has never reported a reading for this room", not "vacant".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->boolean('grms_occupied')->nullable()->after('roomStatus');
            $table->timestamp('grms_occupancy_changed_at')->nullable()->after('grms_occupied');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['grms_occupied', 'grms_occupancy_changed_at']);
        });
    }
};
