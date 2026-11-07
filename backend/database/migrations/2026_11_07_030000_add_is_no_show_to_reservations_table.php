<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A no-show is a cancellation (same reservation_status, same room-release
 * mechanics) with two differences: no refund is ever allowed regardless of
 * the hotel's refund policy, and GRMS is told "no_show" instead of
 * "cancelled". This flag is the only thing distinguishing the two —
 * deliberately not a new top-level reservation_status (see conversation:
 * reusing cancel() keeps every existing status check in the app correct).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->boolean('is_no_show')->default(false)->after('reservation_status');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('is_no_show');
        });
    }
};
