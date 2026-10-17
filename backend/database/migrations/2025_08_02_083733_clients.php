<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
