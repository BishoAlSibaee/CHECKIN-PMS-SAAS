<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // dateTime, not timestamp — see the channex_reservation_requests
            // migration for why: a TIMESTAMP column gets silently converted
            // using the DB session's timezone, which need not match the
            // app's, corrupting "is this notification newer than my last
            // visit" comparisons.
            $table->dateTime('notifications_seen_at')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notifications_seen_at');
        });
    }
};
