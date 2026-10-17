<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-hotel policy for Airbnb's "Request to Book" flow (not all listings use
 * it — most are instant-book — but any connected Airbnb listing can be
 * switched to it on Airbnb's side at any time, and Channex gives no way to
 * query that ahead of time; we only find out when a reservation_request
 * webhook actually arrives). Defaults are the safest possible choice for a
 * hotel that never configures this at all: manual review, reminded hourly,
 * declined automatically if nobody answers in time — never silently
 * auto-accepting a stranger's stay with no human ever looking at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_airbnb_request_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mode', 20)->default('manual_review'); // auto_accept | manual_review | auto_decline
            $table->unsignedInteger('reminder_interval_minutes')->default(60);
            $table->string('fallback_action', 10)->default('decline'); // accept | decline — applied if manual_review times out unanswered
            $table->string('decline_reason', 40)->default('not_comfortable'); // Channex's fixed reason enum — used for auto_decline and a fallback decline
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_airbnb_request_settings');
    }
};
