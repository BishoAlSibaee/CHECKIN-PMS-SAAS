<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_sources', function (Blueprint $table) {
            $table->boolean('is_ota_channel')->default(false)->after('description');
            // Channex's own channel code (e.g. "booking", "expedia", "airbnb") —
            // populated once the source is actually connected via Channex.
            $table->string('channex_channel_code')->nullable()->after('is_ota_channel');
            $table->decimal('default_commission_percent', 5, 2)->nullable()->after('channex_channel_code');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_sources', function (Blueprint $table) {
            $table->dropColumn(['is_ota_channel', 'channex_channel_code', 'default_commission_percent']);
        });
    }
};
