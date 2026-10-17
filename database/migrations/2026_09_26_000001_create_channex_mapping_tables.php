<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channex_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->unique()->constrained('hotels')->cascadeOnDelete();
            $table->string('channex_property_id');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('channex_room_type_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->string('channex_room_type_id');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'room_type_id']);
        });

        Schema::create('channex_rate_plan_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ota_rate_plan_id')->unique()->constrained('ota_rate_plans')->cascadeOnDelete();
            $table->string('channex_rate_plan_id');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channex_rate_plan_mappings');
        Schema::dropIfExists('channex_room_type_mappings');
        Schema::dropIfExists('channex_properties');
    }
};
