<?php

use App\Support\OrphanedInnoDbRepair;
use Illuminate\Database\Migrations\Migration;

/**
 * Self-heal MySQL 1932 orphans (table listed but missing in InnoDB engine).
 * No-op on healthy databases — safe for fresh clones after normal migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new OrphanedInnoDbRepair)->repair();
    }

    public function down(): void
    {
        // Irreversible repair — cannot restore discarded InnoDB tablespaces.
    }
};
