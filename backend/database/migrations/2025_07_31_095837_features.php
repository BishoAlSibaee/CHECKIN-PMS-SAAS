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
        // rooms features
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar')->comment('the feature name_ar');
            $table->string('name_en')->comment('the feature name_en');
            $table->text('description')->nullable()->comment('the feature description');
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
