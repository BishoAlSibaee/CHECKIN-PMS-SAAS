<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('job_number')->unique()->comment("Unique job number for the user");
            $table->unsignedBigInteger('jobtitle_id')->nullable()->comment("Foreign key to user job titles table");
            $table->unsignedBigInteger('department_id')->nullable()->comment("Foreign key to user departments table");
            $table->string('mobile')->unique();
            $table->string('email')->unique();
            $table->unsignedBigInteger('discount_id')->nullable();
            $table->tinyInteger('active')->comment("0 => InActive 1 => Active"); // 0=> Active  1=>InActive
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
