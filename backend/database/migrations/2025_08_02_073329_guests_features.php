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
        // free breakfast and other features
        Schema::create('guests_features', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar')->comment('Name of the feature');
            $table->string('name_en')->comment('the name of the feature in local language');
            $table->string('feature_description')->nullable()->comment('Description of the feature');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guests_features');
    }
};
