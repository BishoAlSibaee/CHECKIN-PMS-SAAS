<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company has a standing negotiated discount (linked to the existing
 * `discounts` table — no new pricing mechanism) and an optional credit
 * limit that blocks new company-billed reservations once exceeded. Lives on
 * the default connection (same as Reservation/JournalEntry), since its only
 * real FK dependents are there too — Client (mysql2) links back via a plain
 * unconstrained column, same non-FK style already used for
 * reservations.client_id across the two databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('contact_mobile')->nullable();
            $table->string('contact_email')->nullable();
            $table->unsignedBigInteger('discount_id')->nullable();
            $table->decimal('credit_limit', 12, 2)->nullable();
            /** 0 = inactive, 1 = active — see Company model constants. */
            $table->unsignedTinyInteger('status')->default(1);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels');
            $table->foreign('discount_id')->references('id')->on('discounts')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users');
            $table->index('hotel_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
