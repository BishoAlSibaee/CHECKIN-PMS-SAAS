<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('group_booking_id')->nullable()->after('reservation_type');
            // set null on delete, never cascade — a group is bookkeeping metadata,
            // the member reservations are real billable stays that must survive it.
            $table->foreign('group_booking_id')->references('id')->on('group_bookings')->nullOnDelete();
            $table->index('group_booking_id');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['group_booking_id']);
            $table->dropIndex(['group_booking_id']);
            $table->dropColumn('group_booking_id');
        });
    }
};
