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
        Schema::create('rooms_features', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_id')->comment('the room this feature belongs to');
            $table->unsignedBigInteger('feature_id')->comment('the feature id');
            $table->integer('number')->default(1)->comment('number of features in the room, default is 1');
            $table->unique(['room_id', 'feature_id'], 'unique_room_feature');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms_features');
    }
};
