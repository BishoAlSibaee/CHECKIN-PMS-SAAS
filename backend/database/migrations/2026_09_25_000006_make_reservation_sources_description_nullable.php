<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The store/update validation and controller code have always treated
 * "description" as optional ('nullable|string', `?? null`), but the column
 * was never actually nullable — surfaced by a real INSERT failure when
 * omitting it. Aligning the schema with the code's actual intent. Raw SQL
 * because doctrine/dbal (required by Schema::table()->change()) isn't
 * installed in this project.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE reservation_sources MODIFY description VARCHAR(255) NULL');
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE reservation_sources MODIFY description VARCHAR(255) NOT NULL DEFAULT ''");
    }
};
