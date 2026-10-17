<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per "tax owner" hotel (a hotel whose shares_tax_with_hotel_id is
 * null) — the VAT/CR registration and ZATCA CSR profile used when reporting
 * invoices for that hotel and for every other hotel that shares its tax
 * registration. A hotel with no row here falls back to config('zatca.csr')
 * (the pre-multi-branch single-tenant defaults), so the original default
 * hotel keeps working without any data entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->unique()->constrained('hotels')->cascadeOnDelete();
            $table->string('vat_number', 20);
            $table->string('cr_number', 20)->nullable();
            $table->string('org_name_ar');
            $table->string('org_name_en');
            $table->string('org_unit_name')->nullable();
            $table->string('location')->nullable();
            $table->string('industry')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_tax_profiles');
    }
};
