<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A "hotel" here is a branch/property — a separate legal entity with its own
 * VAT registration and books (see the multi-branch plan). Named "Hotel" rather
 * than "Property" to avoid colliding with the existing "property" domain
 * (routes/users/property.php), which already means the physical building/
 * floor/room hierarchy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotels', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Every existing building predates the concept of multiple hotels —
        // create a default hotel so the upcoming hotel_id backfill on buildings
        // has somewhere to point.
        DB::table('hotels')->insert([
            'name_ar' => 'الفرع الرئيسي',
            'name_en' => 'Main Branch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('hotels');
    }
};
