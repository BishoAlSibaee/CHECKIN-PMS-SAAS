<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing is per-STAY, not per-guest — the same client could stay once
 * company-billed and once personally, so this lives on Reservation (the
 * source of truth for billing), not on Client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('group_booking_id');
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
