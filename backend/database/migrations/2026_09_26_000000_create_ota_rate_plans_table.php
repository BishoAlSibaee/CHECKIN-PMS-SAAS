<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Sellable rate variants" exposed to OTA channels only (e.g. a discounted
 * non-refundable rate alongside the standard flexible one) — distinct from
 * the existing Pricingplan model, which is seasonal date-range pricing and
 * has no refundability concept. These never affect direct/kiosk booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ota_rate_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            // e.g. -10 for a 10% discounted non-refundable rate, 0 for standard.
            $table->decimal('price_adjustment_percent', 5, 2)->default(0);
            $table->boolean('is_refundable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ota_rate_plans');
    }
};
