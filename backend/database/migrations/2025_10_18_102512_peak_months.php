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
        Schema::create('peak_months', function (Blueprint $table) {
            $table->id();
            $table->string('month_name_ar', 50);
            $table->string('month_name_en', 50);
            $table->tinyInteger('check')->default(0)->comment('0=Normal, 1=Peak Month');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('peak_months');
    }
};
