<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drafted promotional campaigns only — there is deliberately no "sent_at"
 * or "status=sent" value and no column referencing a provider message id.
 * Sending is not implemented in this phase; see TaqnyatService (no send
 * method exists there either) and the plan doc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->string('channel')->default('sms');
            $table->text('message_body');
            $table->string('audience_type'); // 'manual' | 'classification'
            $table->unsignedBigInteger('guest_classification_id')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->string('status')->default('draft'); // only 'draft' is ever written in this phase
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('promo_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_campaign_id')->constrained('promo_campaigns')->cascadeOnDelete();
            $table->unsignedBigInteger('client_id');
            $table->string('phone_snapshot')->nullable();
            $table->timestamps();

            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_campaign_recipients');
        Schema::dropIfExists('promo_campaigns');
    }
};
