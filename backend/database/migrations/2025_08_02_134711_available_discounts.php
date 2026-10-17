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
        Schema::create('discounts',function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment("Name of the discount");
            $table->boolean('is_percentage')->default(false)->comment("percentage is active or not");
            $table->integer('percent')->comment("Discount percentage");
            $table->boolean('is_fixed')->default(false)->comment("Fixed amount discount is active or not");
            $table->double('fixed_amount')->default(0)->comment("Fixed amount for the discount");
            $table->boolean('is_active')->default(true)->comment("0 => InActive 1 => Active");
            $table->timestamps();
            $table->comment("Table to store available discounts for users");
            // if both is_percentage and is_fixed are true, then the percentage will be active but the amount should not be more than the fixed amount
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
