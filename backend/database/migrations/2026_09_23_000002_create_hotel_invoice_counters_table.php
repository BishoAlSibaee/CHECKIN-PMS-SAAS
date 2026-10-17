<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One continuous invoice-number sequence per tax entity (per "tax owner"
 * hotel id — see HotelTaxResolver::resolveEffectiveTaxHotelId). Branches that
 * share a tax registration share one counter, matching how a single VAT
 * registration must not issue duplicate invoice numbers across its branches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_invoice_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_hotel_id')->unique()->constrained('hotels')->cascadeOnDelete();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });

        // Existing invoice numbers were derived from the invoices table's own
        // auto-increment id (INV-000001 style). Seed the default hotel's
        // counter past the highest id already used so numbering stays
        // continuous instead of restarting at 1 and colliding.
        $defaultHotelId = DB::table('hotels')->orderBy('id')->value('id');
        if ($defaultHotelId !== null) {
            $highestInvoiceId = (int) DB::table('invoices')->max('id');
            DB::table('hotel_invoice_counters')->insert([
                'tax_hotel_id' => $defaultHotelId,
                'next_number' => $highestInvoiceId + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_invoice_counters');
    }
};
