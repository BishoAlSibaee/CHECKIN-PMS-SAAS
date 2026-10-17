<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * client_classifications belongs on mysql2 (with clients), not the primary PMS database.
     */
    public function up(): void
    {
        if (Schema::hasTable('client_classifications')) {
            Schema::drop('client_classifications');
        }
    }

    public function down(): void
    {
        // Intentionally empty — wrong-db copy must not be recreated.
    }
};
