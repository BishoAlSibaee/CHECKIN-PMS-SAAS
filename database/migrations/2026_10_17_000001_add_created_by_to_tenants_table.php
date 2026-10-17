<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks which platform operator onboarded each customer — an audit trail,
 * nothing more, so it's nullable and nulls out (never blocks) if that
 * PlatformAdmin account is ever removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('created_by_platform_admin_id')
                ->nullable()
                ->after('is_active')
                ->constrained('platform_admins')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_platform_admin_id');
        });
    }
};
