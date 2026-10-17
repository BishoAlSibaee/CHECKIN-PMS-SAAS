<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A general-purpose staff notification feed (Airbnb requests, new OTA
 * bookings, pricing changes, ...) — not Laravel's own Notifiable/database
 * notifications table (this app doesn't use that), named distinctly
 * (app_notifications) to avoid ever colliding with it.
 *
 * hotel_id is nullable because not every event is hotel-scoped: room types
 * and their pricing plans have no hotel_id column in this schema (a global
 * catalog), so a pricing-change notification has nowhere hotel-specific to
 * point to and is shown to every hotel's staff who hold the permission.
 *
 * There is deliberately no per-notification read_at — see
 * add_notifications_seen_at_to_users_table: read state is a single
 * "seen up to this moment" timestamp per user, not per-item, matching the
 * simple "open the bell, badge clears" UX this is built for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('title_key');
            $table->json('params')->nullable();
            $table->string('link')->nullable();
            // Only staff holding this permission (checked against whichever
            // hotel is currently active for them) will ever see the item —
            // e.g. a receptionist shouldn't see "pricing plan updated".
            $table->string('required_permission')->nullable();
            $table->timestamps();

            $table->index(['hotel_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
