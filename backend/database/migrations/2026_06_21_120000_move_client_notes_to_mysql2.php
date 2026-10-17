<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('mysql2')->hasTable('client_notes')) {
            Schema::connection('mysql2')->create('client_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
                $table->string('title');
                $table->text('description');
                $table->timestamps();
            });
        }

        if (Schema::hasTable('client_notes')) {
            Schema::drop('client_notes');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_notes')) {
            Schema::create('client_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
                $table->string('title');
                $table->text('description');
                $table->timestamps();
            });
        }

        if (Schema::connection('mysql2')->hasTable('client_notes')) {
            Schema::connection('mysql2')->drop('client_notes');
        }
    }
};
