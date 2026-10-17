<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Schema::create('update_reservations', function (Blueprint $table) {
        //     $table->id();
        //     $table->integer('reservation_id');
        //     $table->integer('old_room_id')->nullable();
        //     $table->integer('new_room_id')->nullable();
        //     $table->integer('old_room_number')->nullable();
        //     $table->integer('new_room_number')->nullable();
        //     $table->date('old_start_date')->nullable();
        //     $table->date('new_start_date')->nullable();
        //     $table->date('old_expire_date')->nullable();
        //     $table->date('new_expire_date')->nullable();
        //     $table->json('old_facility_ids')->nullable();
        //     $table->json('new_facility_ids')->nullable();
        //     $table->tinyInteger('is_update_room')->default(0);
        //     $table->tinyInteger('is_update_date')->default(0);
        //     $table->tinyInteger('is_update_facility')->default(0);
        //     $table->date('update_date')->default(DB::raw('CURRENT_DATE'));
        //     $table->integer('update_by')->comment("Admin_Id");
        // });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //Schema::dropIfExists('update_reservation');
    }
};
