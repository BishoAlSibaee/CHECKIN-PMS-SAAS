<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_daily_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->onDelete('cascade');
            $table->foreignId('reservation_room_id')->constrained('reservation_rooms')->onDelete('cascade');
            $table->foreignId('room_id')->constrained('rooms')->onDelete('cascade');
            $table->date('charge_date');
            $table->decimal('base_amount', 12, 2);
            $table->boolean('is_peak_day')->default(false);
            $table->boolean('is_in_plan')->default(false);
            $table->string('price_source', 32)->default('min');
            $table->tinyInteger('rent_type')->default(0);
            $table->timestamps();

            $table->unique(['reservation_room_id', 'charge_date'], 'res_room_charge_date_unique');
            $table->index(['charge_date', 'reservation_id']);
            $table->index(['room_id', 'charge_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_daily_charges');
    }
};
