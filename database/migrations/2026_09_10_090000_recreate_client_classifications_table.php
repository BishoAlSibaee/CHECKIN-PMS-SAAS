<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ensure_client_classifications_on_mysql2 + drop_client_classifications_from_primary_db
 * assume "mysql2" is a separate physical database from "mysql". In any environment where
 * DB2_DATABASE equals DB_DATABASE (same host+db, just a second connection name — the case
 * here), those two migrations combine to delete the only copy of the table: "ensure" is a
 * no-op because it already exists, then "drop from primary" removes it for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('mysql2')->hasTable('client_classifications')) {
            Schema::connection('mysql2')->create('client_classifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('classifications_id');
                $table->unsignedBigInteger('client_id');
                $table->timestamps();

                $table->unique('client_id');
                $table->index('classifications_id');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty — see drop_client_classifications_from_primary_db for why
        // dropping this table is not safe to automate.
    }
};
