<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Convenience only, not billing-authoritative (that's reservations.company_id
 * on the default connection). No real FK constraint — Client lives on the
 * mysql2 connection while companies lives on the default one, the same
 * unconstrained cross-database relationship already used for
 * reservations.client_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('mysql2')->hasColumn('clients', 'company_id')) {
            return;
        }

        Schema::connection('mysql2')->table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('loyalty_points_balance');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql2')->table('clients', function (Blueprint $table) {
            $table->dropIndex(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
