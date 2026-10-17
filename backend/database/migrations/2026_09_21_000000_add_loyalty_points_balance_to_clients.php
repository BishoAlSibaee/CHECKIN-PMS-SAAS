<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Clients live on the "mysql2" connection — see app/Models/Client.php.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('mysql2')->hasColumn('clients', 'loyalty_points_balance')) {
            return;
        }

        Schema::connection('mysql2')->table('clients', function (Blueprint $table) {
            $table->unsignedInteger('loyalty_points_balance')->default(0)->after('nationality');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql2')->table('clients', function (Blueprint $table) {
            $table->dropColumn('loyalty_points_balance');
        });
    }
};
