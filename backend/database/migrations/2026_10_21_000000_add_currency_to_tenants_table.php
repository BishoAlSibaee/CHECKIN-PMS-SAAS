<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chosen once when the tenant is onboarded (see TenantsController::store());
 * every hotel/branch added later to the same tenant shares it. ZATCA
 * reporting is gated on this being 'SAR' — see
 * ZatcaCheckoutReportingService::shouldReportOnCheckout().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('currency', 3)->default('SAR')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
