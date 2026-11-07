<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency ledger for GRMS v1 inbound events/charges — the contract
 * requires "the same id is processed once" (a repeat answers 'duplicate').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grms_inbound_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->string('type');
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grms_inbound_events');
    }
};
