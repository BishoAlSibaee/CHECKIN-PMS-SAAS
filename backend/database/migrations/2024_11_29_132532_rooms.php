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
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('building_id')->comment('building room belongs to');
            $table->unsignedBigInteger('floor_id')->comment('floor room belongs to');
            $table->unsignedBigInteger('suite_id')->comment('suite room belongs to')->nullable();
            $table->string('number')->comment('room number');
            $table->unsignedBigInteger('room_type_id')->comment('the room type');
            $table->integer('capacity')->default(2)->comment('room capacity');
            $table->integer('roomStatus', false, true)->default(1)->comment('room status, 1 for available, 2 for occupied, 3 for needs preparation , 4 for out of service');
            $table->string('lock_data')->nullable()->unique()->comment('lock data for the room, can be used for smart locks');
            $table->tinyInteger('active')->default(1)->comment('1 active | 0 Inactive');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
