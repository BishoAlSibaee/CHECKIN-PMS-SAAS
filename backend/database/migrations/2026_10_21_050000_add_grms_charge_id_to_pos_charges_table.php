<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GRMS v1 contract idempotency key for charges posted via
 * POST /api/grms/v1/charges — the SAME charge_id posted twice must answer
 * the first answer, never double-post. One GRMS "charge" can carry several
 * line items, so this is NOT unique by itself — one row per item, grouped
 * by this column (see GrmsChargeController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_charges', function (Blueprint $table) {
            $table->string('grms_charge_id')->nullable()->after('id');
            $table->index('grms_charge_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_charges', function (Blueprint $table) {
            $table->dropColumn('grms_charge_id');
        });
    }
};
