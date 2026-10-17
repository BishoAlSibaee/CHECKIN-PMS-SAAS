<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Same retrofit pattern as add_hotel_id_to_buildings_table.php: every
 * existing hotel predates the Tenant concept, so backfill it onto the
 * default tenant the previous migration just created — a safe no-op for any
 * deployment that (like both live ones today) only has one tenant in
 * practice so far.
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaultTenantId = DB::table('tenants')->orderBy('id')->value('id');

        Schema::table('hotels', function (Blueprint $table) use ($defaultTenantId) {
            $table->foreignId('tenant_id')->default($defaultTenantId)->after('id')->constrained('tenants')->restrictOnDelete();
        });

        DB::table('hotels')->whereNull('tenant_id')->update(['tenant_id' => $defaultTenantId]);
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
