<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('description')->nullable();
            $table->double('Max_daily_price')->default(0)->comment('Maximum daily price for the room type');
            $table->double('Min_daily_price')->default(0)->comment('Minimum daily price for the room type');
            $table->double('Max_monthly_price')->default(0)->comment('Maximum monthly price for the room type');
            $table->double('Min_monthly_price')->default(0)->comment('Minimum monthly price for the room type');
            $table->tinyInteger('active_type')->default(1)->comment('Type of activity: 0 for inactive, 1 for as per day , 2 for all max');
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};
