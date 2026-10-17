<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Channex's webhook event name (booking_new / booking_modification /
 * booking_cancellation) was received but discarded — ingestion had no way
 * to tell a cancellation or a date change apart from a brand-new booking,
 * so either one produced a second, duplicate reservation that then crashed
 * on the [reservation_source_id, external_booking_id] unique constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channex_booking_revisions', function (Blueprint $table) {
            $table->string('event', 50)->nullable()->after('channex_revision_id');
        });
    }

    public function down(): void
    {
        Schema::table('channex_booking_revisions', function (Blueprint $table) {
            $table->dropColumn('event');
        });
    }
};
