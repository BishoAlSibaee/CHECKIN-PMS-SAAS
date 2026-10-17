<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency guard for incoming Channex bookings — the webhook and the
 * 15-minute backup poller can both see the same revision; only the first
 * one to process it should actually create a reservation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channex_booking_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('channex_booking_id');
            $table->string('channex_revision_id');
            $table->string('channex_property_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending, processed, failed, ack_failed
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->json('raw_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['channex_booking_id', 'channex_revision_id'], 'channex_revisions_booking_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channex_booking_revisions');
    }
};
