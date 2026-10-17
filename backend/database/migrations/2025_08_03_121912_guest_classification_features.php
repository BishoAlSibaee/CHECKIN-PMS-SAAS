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
        Schema::create('guest_classification_features', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('guest_classification_id')->comment('ID of the guest classification');
            $table->unsignedBigInteger('guest_feature_id')->comment('ID of the guest feature');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_classification_features');
    }
};
