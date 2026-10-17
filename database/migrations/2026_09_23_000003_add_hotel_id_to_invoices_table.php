<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $defaultHotelId = DB::table('hotels')->orderBy('id')->value('id');

        Schema::table('invoices', function (Blueprint $table) use ($defaultHotelId) {
            // hotel_id: the physical branch that issued the invoice (reporting/filtering).
            // tax_hotel_id: the tax entity the invoice number/ICV/PIH chain was drawn
            // from — usually the same hotel, but the shared-tax hotel when the issuing
            // branch shares its VAT registration with another branch.
            $table->foreignId('hotel_id')->default($defaultHotelId)->after('reservation_id')->constrained('hotels');
            $table->foreignId('tax_hotel_id')->default($defaultHotelId)->after('hotel_id')->constrained('hotels');
        });

        DB::table('invoices')->whereNull('hotel_id')->update(['hotel_id' => $defaultHotelId]);
        DB::table('invoices')->whereNull('tax_hotel_id')->update(['tax_hotel_id' => $defaultHotelId]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_hotel_id');
            $table->dropConstrainedForeignId('hotel_id');
        });
    }
};
