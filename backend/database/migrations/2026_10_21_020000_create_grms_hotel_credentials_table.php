<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GRMS v1 contract: one HMAC credential per hotel per direction (PMS -> GRMS
 * outbound signing, GRMS -> PMS inbound verification), replacing the single
 * global GRMS_API_TOKEN/GRMS_WEBHOOK_SECRET the legacy integration uses.
 * `secret` must be reversible (not a one-way hash like KioskDevice's
 * token_hash) because HMAC needs the real value on both ends every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grms_hotel_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['outbound', 'inbound']);
            $table->string('key_id')->unique();
            $table->text('secret');
            $table->boolean('is_active')->default(true);
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();

            // "Only one active credential per hotel+direction" is enforced
            // in GrmsCredentialService (deactivate-then-create in one
            // transaction), not here — a DB-level unique index on is_active
            // would also block keeping multiple INACTIVE rows for rotation
            // history, which is the opposite of what's wanted.
            $table->index(['hotel_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grms_hotel_credentials');
    }
};
