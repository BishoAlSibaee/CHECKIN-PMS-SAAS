<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound event queue for the GRMS v1 contract (POST /api/pms/v1/events).
 * A pending event for the SAME entity_type+entity_id is replaced by a newer
 * one rather than stacking duplicates (the contract's own rule) — see
 * GrmsOutboxService::enqueue(). Retry schedule matches the contract exactly:
 * 10s, 1m, 5m, 30m, 2h, 6h, then 'review' (a human looks at it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grms_outbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('entity_type');
            $table->string('entity_id');
            $table->unsignedInteger('version');
            $table->json('payload');
            $table->enum('status', ['pending', 'sent', 'accepted', 'rejected', 'stale', 'review'])
                ->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error')->nullable();
            $table->boolean('sync')->default(false);
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
            $table->index(['hotel_id', 'entity_type', 'entity_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grms_outbox_events');
    }
};
