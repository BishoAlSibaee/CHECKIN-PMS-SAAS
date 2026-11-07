<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monotonic per-reservation version, for the GRMS v1 contract's staleness
 * check (an inbound/outbound event older than the version we already have
 * is ignored rather than applied) — see Reservation::booted()'s saving hook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
