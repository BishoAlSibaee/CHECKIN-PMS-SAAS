<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NULL = no expiry (the platform's own tenant, and any permanent paying
 * customer). A real timestamp is a trial/subscription deadline — past it,
 * every user belonging to that tenant's hotels is locked out immediately
 * (see UsersController::login() and ResolveCurrentHotel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('subscription_ends_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('subscription_ends_at');
        });
    }
};
