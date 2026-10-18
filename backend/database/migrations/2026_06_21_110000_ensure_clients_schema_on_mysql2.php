<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guests live on mysql2. Rebuild a truncated/orphan `clients` table so seeders and booking work after clone/repair.
 *
 * Renamed from 2026_07_16_131900 to 2026_06_21_110000 (an hour before
 * move_client_notes_to_mysql2, same day) — that migration adds a real FK
 * from client_notes.client_id to clients(id) on mysql2, which only works if
 * this one has already created clients there. On every already-migrated
 * database this is a harmless no-op re-run (see the early-return check
 * below); a truly fresh install previously failed on that FK with "Cannot
 * add foreign key constraint" because clients didn't exist on mysql2 yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('mysql2');

        if ($schema->hasTable('clients') && $schema->hasColumn('clients', 'IdNumber')) {
            return;
        }

        DB::connection('mysql2')->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $schema->dropIfExists('clients');
            $schema->create('clients', function (Blueprint $table) {
                $table->id();
                $table->string('first_name')->comment('First name of the client');
                $table->string('last_name')->comment('Last name of the client');
                $table->string('email')->unique()->nullable()->comment('Email of the client');
                $table->string('international_code')->comment('International code of the client');
                $table->string('mobile')->unique()->comment('Mobile number of the client');
                $table->enum('IdType', ['ID', 'PASSPORT']);
                $table->string('IdNumber')->unique()->comment('ID or Passport number of the client');
                $table->date('birth_date')->nullable()->comment('Birth date of the client');
                $table->enum('gender', ['MALE', 'FEMALE'])->comment('Gender of the client');
                $table->enum('guest_type', ['CITIZEN', 'RESIDENT', 'GULF CITIZEN', 'VISITOR'])->comment('Type of the client');
                $table->string('nationality')->nullable();
                $table->timestamps();
            });
        } finally {
            DB::connection('mysql2')->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function down(): void
    {
        // Non-destructive: do not drop live guest data.
    }
};
