<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GRMS v1 contract: guest.updated has its own version sequence per guest.id,
 * same idea as Reservation::version (see Reservation::booted()).
 *
 * mysql2 is a SEPARATE connection from the one `migrate:fresh` resets —
 * fresh only drops/rebuilds the default connection's tables and replays
 * every migration from scratch, so a migration that alters a mysql2 table
 * runs TWICE against the SAME physical table (its data survives the
 * "fresh") unless it's written to be idempotent, exactly like
 * 2026_06_21_110000_ensure_clients_schema_on_mysql2 already documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Client::$connection is 'mysql2' — a plain Schema::table() would
        // target the default connection and miss this table entirely.
        $schema = Schema::connection('mysql2');
        if ($schema->hasColumn('clients', 'version')) {
            return;
        }

        $schema->table('clients', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('id');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('mysql2');
        if (!$schema->hasColumn('clients', 'version')) {
            return;
        }

        $schema->table('clients', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
