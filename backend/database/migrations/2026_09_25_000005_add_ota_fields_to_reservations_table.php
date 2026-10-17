<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Null for non-OTA reservations (walk-in, kiosk, direct phone booking).
            $table->string('ota_payment_type', 20)->nullable()->after('reservation_source_id');
            $table->string('external_booking_id')->nullable()->after('ota_payment_type');
            $table->decimal('external_commission_amount', 10, 2)->nullable()->after('external_booking_id');

            $table->unique(['reservation_source_id', 'external_booking_id'], 'reservations_source_external_booking_unique');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique('reservations_source_external_booking_unique');
            $table->dropColumn(['ota_payment_type', 'external_booking_id', 'external_commission_amount']);
        });
    }
};
