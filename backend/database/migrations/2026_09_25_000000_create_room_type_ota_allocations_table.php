<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many rooms of a given type a hotel manually releases to OTA channels
 * (Booking.com, Expedia, ... via Channex) — a staff-set ceiling, not an
 * automatic share of physical inventory. Distributed identically to every
 * connected channel; there is no per-channel split.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_type_ota_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->unsignedInteger('allocated_rooms')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['hotel_id', 'room_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_type_ota_allocations');
    }
};
