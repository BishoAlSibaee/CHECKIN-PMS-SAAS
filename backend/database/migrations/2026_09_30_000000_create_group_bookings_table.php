<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A group/event spans one or more independent Reservation rows (different
 * clients, different dates per person) — see reservations.group_booking_id
 * in the next migration. No financial fields live here: total/paid/balance
 * are always summed live from member reservations (GroupBookingFinancialService)
 * so this table can never drift from the real, billable reservation rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_mobile')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('group_discount_percent', 5, 2)->default(0);
            /** 0 = Draft, 1 = Active, 2 = Cancelled — see GroupBooking model constants. */
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index('hotel_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_bookings');
    }
};
