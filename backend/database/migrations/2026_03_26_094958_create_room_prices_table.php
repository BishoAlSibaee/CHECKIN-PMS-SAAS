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
        Schema::create('room_prices', function (Blueprint $table) {
            $table->id();
$table->foreignId('reservation_room_id')->nullable()->constrained('reservation_rooms')->onDelete('cascade');
            $table->double('pricing_plan_daily')->nullable();
            $table->double('pricing_plan_monthly')->nullable();
$table->double('max_price')->nullable();
            $table->double('min_price')->nullable();
            $table->double('max_month')->nullable();
            $table->double('min_month')->nullable();
            $table->date('date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_prices');
    }
};
