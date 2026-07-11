<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
        if (Schema::connection('mysql2')->hasTable('client_classifications')) {
            Schema::connection('mysql2')->drop('client_classifications');
        }
    }
};
