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
        Schema::create('reservation_taxes',function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id')->comment('reservation id');
            $table->unsignedBigInteger('tax_id')->comment('the applied tax id');
            $table->unique(['reservation_id','tax_id']);
            $table->double('amount')->comment('the tax amount');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservetion_taxes');
    }
};
