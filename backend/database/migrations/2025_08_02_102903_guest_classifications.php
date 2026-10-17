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
        Schema::create('guest_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar')->unique()->comment('Name of the guest classification');
            $table->string('name_en')->unique()->nullable()->comment('Local name of the guest classification');
            $table->string('description')->nullable()->comment('Description of the guest classification');
            $table->unsignedBigInteger('discount_id')->nullable()->comment('Type of discount applied to the guest classification');
            $table->tinyInteger(column: 'active')->default(1)->comment('0 for inactive, 1 for active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_classifications');
    }
};
