<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks one Airbnb "Request to Book" from the moment Channex's
 * reservation_request webhook arrives until it's resolved (accepted or
 * declined) — either by staff, by the hotel's auto_accept/auto_decline
 * policy, or by the 24-hour fallback. Accepting/declining here does NOT
 * itself create a reservation — per Channex's own docs, Airbnb still has to
 * charge the guest and re-confirm availability after an accept, and only
 * THEN sends the normal booking_new webhook that ChannexBookingIngestionService
 * already handles. This table exists purely to track the pending decision
 * and its 24-hour deadline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channex_reservation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('channex_property_id');
            // The id used to call POST /live_feed/{id}/resolve — Channex's
            // docs don't fully spell out which payload field carries it for
            // reservation_request specifically (confirmed present for
            // alteration_request as "payload.id"); stored as a plain string
            // so whichever field we end up reading from still fits.
            $table->string('live_feed_event_id')->unique();
            $table->string('message_thread_id')->nullable();
            $table->string('channex_booking_id')->nullable(); // from payload.bms.booking_id, to cross-reference once the real booking arrives
            $table->json('raw_payload'); // the "bms" object — room_type_id, rate_plan_id, dates, customer, amount, ...
            // dateTime, not timestamp: a MySQL TIMESTAMP column is silently
            // converted using the DB session's timezone, which need not match
            // the app's (config('app.timezone')) — corrupting the 24h
            // deadline math this column exists for. dateTime stores/reads the
            // literal wall-clock value Carbon writes, with no conversion.
            $table->dateTime('received_at'); // Channex gives no expiry field; the 24h deadline is received_at + 24h
            $table->string('status', 20)->default('pending'); // pending | resolved
            $table->string('resolution', 10)->nullable(); // accept | decline
            $table->string('resolved_by', 20)->nullable(); // staff | auto_accept | auto_decline | fallback
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('last_reminder_sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channex_reservation_requests');
    }
};
