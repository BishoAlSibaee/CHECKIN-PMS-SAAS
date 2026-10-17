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
        Schema::create('client_classifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('classifications_id');
            $table->foreign('classifications_id')->references('id')->on('guest_classifications');
            $table->unsignedBigInteger('client_id');
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */

    public function down(): void
    {
        Schema::dropIfExists('client_classifications');
    }
};
