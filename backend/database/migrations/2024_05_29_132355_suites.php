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
        Schema::create('suites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('building_id')->comment('ID of the building this suite belongs to');
            $table->unsignedBigInteger('floor_id')->comment('ID of the floor this suite is located on');
            $table->string('number')->comment('Suite number');
            $table->boolean('suiteStatus')->default(false)->comment('Status of the suite (true for booked, false for unbooked)');
            $table->tinyInteger('active')->default(1)->comment('1 active | 0 Inactive');
            $table->string('lock_id')->nullable()->unique()->comment('Unique lock ID for the suite');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suites');
    }
};
