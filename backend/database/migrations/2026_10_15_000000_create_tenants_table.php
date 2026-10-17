<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A "tenant" is the account/owner level above Hotel — one tenant can own
 * several hotels (branches), which continue sharing data as they do today
 * (clients, etc.); a DIFFERENT tenant is a separate, unrelated customer and
 * must never see another tenant's data. See the create_hotels_table
 * migration this mirrors — same "insert one default row immediately" pattern
 * so every existing hotel has somewhere unambiguous to point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('tenants')->insert([
            'name_ar' => 'المستأجر الافتراضي',
            'name_en' => 'Default Tenant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
