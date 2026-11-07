<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-hotel GRMS protocol selection — a hotel is on exactly one of the two
 * at a time (legacy room-ready webhook + setReservationApi/.../, or the v1
 * contract). See App\Http\Middleware\EnforceGrmsProtocol.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->enum('pms_protocol', ['legacy', 'v1'])->default('legacy')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn('pms_protocol');
        });
    }
};
