<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hotel is normally its own tax entity (own VAT/CR number, own ZATCA
 * certificate, own invoice sequence). When a branch shares the same tax
 * registration as another branch (common for chains registered under one
 * commercial registration), it points here instead of holding its own
 * hotel_tax_profiles row — see HotelTaxResolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->foreignId('shares_tax_with_hotel_id')
                ->nullable()
                ->after('is_active')
                ->constrained('hotels')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shares_tax_with_hotel_id');
        });
    }
};
