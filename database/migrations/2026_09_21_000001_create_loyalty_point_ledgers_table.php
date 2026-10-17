<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lives on the default (primary) connection — deliberately NOT alongside clients
// on mysql2, since reservation_id needs a real FK to reservations here. client_id
// has no DB-level FK (Client lives on a different connection); the app-level
// LoyaltyPointsService is the only writer and is responsible for keeping it
// consistent with clients.loyalty_points_balance.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_point_ledgers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignId('reservation_pay_id')->nullable()->constrained('reservation_pay')->nullOnDelete();
            $table->integer('points')->comment('Positive = earned, negative = redeemed or clawed back');
            $table->string('reason');
            $table->unsignedBigInteger('user_id')->nullable()->comment('Staff who triggered a manual redemption; null for automatic earn/clawback');
            $table->timestamps();

            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_point_ledgers');
    }
};
