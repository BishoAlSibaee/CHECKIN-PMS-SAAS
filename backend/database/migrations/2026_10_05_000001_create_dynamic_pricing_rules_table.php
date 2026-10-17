<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->string('metric'); // 'occupancy' | 'lead_time'
            $table->unsignedInteger('range_from');
            $table->unsignedInteger('range_to')->nullable(); // null = open-ended upper bound
            $table->decimal('adjustment_percent', 5, 2);
            $table->timestamps();

            $table->index(['room_type_id', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_pricing_rules');
    }
};
