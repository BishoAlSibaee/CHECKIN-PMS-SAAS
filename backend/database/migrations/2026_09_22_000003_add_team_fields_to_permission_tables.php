<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retrofits Spatie's "teams" feature onto an install that already ran its
 * permission migrations with teams disabled — adapted from the package's own
 * database/migrations/add_teams_fields.php.stub (see vendor/spatie/laravel-permission),
 * which is exactly for this scenario per that package's own instructions.
 * team_foreign_key is configured as "hotel_id" (config/permission.php) — a
 * user's active role assignment is scoped to whichever hotel is currently
 * selected (see ResolveCurrentHotel middleware).
 */
return new class extends Migration
{
    public function up(): void
    {
        $teams = config('permission.teams');
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';

        if (! $teams) {
            throw new \Exception('config/permission.php "teams" must be enabled before running this migration.');
        }

        $defaultHotelId = DB::table('hotels')->orderBy('id')->value('id') ?? 1;

        if (! Schema::hasColumn($tableNames['roles'], $columnNames['team_foreign_key'])) {
            Schema::table($tableNames['roles'], function (Blueprint $table) use ($columnNames) {
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable()->after('id');
                $table->index($columnNames['team_foreign_key'], 'roles_team_foreign_key_index');

                $table->dropUnique('roles_name_guard_name_unique');
                $table->unique([$columnNames['team_foreign_key'], 'name', 'guard_name']);
            });
        }

        if (! Schema::hasColumn($tableNames['model_has_permissions'], $columnNames['team_foreign_key'])) {
            Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($tableNames, $columnNames, $pivotPermission, $defaultHotelId) {
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->default($defaultHotelId);
                $table->index($columnNames['team_foreign_key'], 'model_has_permissions_team_foreign_key_index');

                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign([$pivotPermission]);
                }
                $table->dropPrimary();

                $table->primary(
                    [$columnNames['team_foreign_key'], $pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_permissions_permission_model_type_primary'
                );
                if (DB::getDriverName() !== 'sqlite') {
                    $table->foreign($pivotPermission)
                        ->references('id')->on($tableNames['permissions'])->onDelete('cascade');
                }
            });
        }

        if (! Schema::hasColumn($tableNames['model_has_roles'], $columnNames['team_foreign_key'])) {
            Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($tableNames, $columnNames, $pivotRole, $defaultHotelId) {
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->default($defaultHotelId);
                $table->index($columnNames['team_foreign_key'], 'model_has_roles_team_foreign_key_index');

                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign([$pivotRole]);
                }
                $table->dropPrimary();

                $table->primary(
                    [$columnNames['team_foreign_key'], $pivotRole, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_roles_role_model_type_primary'
                );
                if (DB::getDriverName() !== 'sqlite') {
                    $table->foreign($pivotRole)
                        ->references('id')->on($tableNames['roles'])->onDelete('cascade');
                }
            });
        }

        // Existing roles were created before hotels existed — attach them to the
        // default hotel so they keep working exactly as before for everyone
        // already assigned one, instead of becoming orphaned/invisible.
        DB::table($tableNames['roles'])
            ->whereNull($columnNames['team_foreign_key'])
            ->update([$columnNames['team_foreign_key'] => $defaultHotelId]);

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        // Not reversed: Spatie's own stub doesn't reverse this either, and
        // unwinding the composite primary keys safely needs care beyond a
        // generic down(). Restore from a backup if this needs to be undone.
    }
};
