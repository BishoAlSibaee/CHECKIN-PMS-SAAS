<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client (guest) records live on the mysql2 connection, tenants on the
 * default mysql connection — the same unconstrained cross-database
 * relationship already used for clients.company_id (see
 * add_company_id_to_clients_table.php), so no real FK constraint here
 * either. Every existing client is backfilled onto the default tenant,
 * safe for the same reason hotels.tenant_id's backfill is: today there's
 * only ever one tenant in practice per deployment.
 *
 * email/mobile/IdNumber were globally unique — two separate tenants could
 * never register a guest with the same phone number. Replaced with
 * composite (tenant_id, column) uniqueness instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        // migrate:fresh only resets the DEFAULT connection's tables (and its
        // migrations tracking row) — mysql2's `clients` table survives, so a
        // second "fresh" run would otherwise try to re-add this column. Same
        // guard as add_company_id_to_clients_table.php.
        if (Schema::connection('mysql2')->hasColumn('clients', 'tenant_id')) {
            return;
        }

        $defaultTenantId = DB::connection('mysql')->table('tenants')->orderBy('id')->value('id');

        Schema::connection('mysql2')->table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->index('tenant_id');
        });

        DB::connection('mysql2')->table('clients')->whereNull('tenant_id')->update(['tenant_id' => $defaultTenantId]);

        Schema::connection('mysql2')->table('clients', function (Blueprint $table) use ($defaultTenantId) {
            // Same safety-net as hotels.tenant_id's own default: code (and
            // existing tests/fixtures) that creates a Client without
            // knowing about tenants yet still lands on the one real tenant
            // every deployment has today, rather than crashing outright.
            $table->unsignedBigInteger('tenant_id')->default($defaultTenantId)->nullable(false)->change();

            $table->dropUnique('clients_email_unique');
            $table->dropUnique('clients_mobile_unique');
            $table->dropUnique('clients_idnumber_unique');

            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'mobile']);
            $table->unique(['tenant_id', 'IdNumber']);
        });
    }

    public function down(): void
    {
        Schema::connection('mysql2')->table('clients', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
            $table->dropUnique(['tenant_id', 'mobile']);
            $table->dropUnique(['tenant_id', 'IdNumber']);

            $table->unique('email');
            $table->unique('mobile');
            $table->unique('IdNumber');

            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
