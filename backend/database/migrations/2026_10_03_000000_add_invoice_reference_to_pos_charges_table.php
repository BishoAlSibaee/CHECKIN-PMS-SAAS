<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The restaurant/outlet's own invoice or receipt number from its till —
 * purely a reference for the guest/staff to look the sale up at the outlet
 * itself later (same pattern OPERA uses: the PMS folio line references the
 * POS check number, it never duplicates the POS's own itemized receipt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_charges', function (Blueprint $table) {
            $table->string('invoice_reference')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('pos_charges', function (Blueprint $table) {
            $table->dropColumn('invoice_reference');
        });
    }
};
