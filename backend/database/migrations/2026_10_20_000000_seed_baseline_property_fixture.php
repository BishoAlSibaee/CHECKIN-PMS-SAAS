<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A number of long-standing Feature tests (RbacMatrixTest, SiteSmokeTest,
 * FormRequestValidationTest, ...) call e.g. Building::where('active',1)->first()
 * or hit list endpoints (floors/suites) expecting at least one row to exist,
 * without ever creating that row themselves — an "ambient fixture" they
 * implicitly borrowed from whichever OTHER test happened to create one
 * earlier in the same process. That only ever worked because this suite
 * historically ran without per-test transaction rollback, so such rows
 * persisted for the rest of the run; once DatabaseTransactions was added
 * broadly (see tests/Feature/**), each test's own fixtures are rolled back
 * at the end of that same test, and these ambient-dependency tests started
 * finding nothing.
 *
 * Seeding one baseline property here — in a migration, like create_hotels_table
 * already does for the default Hotel row — guarantees it exists from the
 * moment the schema is built and is never touched by any test transaction,
 * exactly mirroring how the default Hotel row already survives the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Unconditional, same as create_hotels_table's default Hotel row —
        // "testing only" isn't distinguishable here: EssentialDatabaseSeederTest
        // itself runs migrate:fresh from inside a PHPUnit test, so
        // app()->environment('testing') is true for both the outer suite's
        // initial fresh install AND that test's own internal one.
        if (DB::table('buildings')->exists()) {
            return;
        }

        $hotelId = (int) (DB::table('hotels')->orderBy('id')->value('id') ?? 1);

        $roomTypeId = DB::table('room_types')->insertGetId([
            'name_ar' => 'غرفة قياسية',
            'name_en' => 'Standard Room',
            'description' => 'Standard Room — غرفة قياسية',
            'Min_daily_price' => 150,
            'Max_daily_price' => 300,
            'Min_monthly_price' => 3500,
            'Max_monthly_price' => 6500,
            'active_type' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $buildingId = DB::table('buildings')->insertGetId([
            'hotel_id' => $hotelId,
            'name' => 'Main Building',
            'number' => 'A',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $floorId = DB::table('floors')->insertGetId([
            'building_id' => $buildingId,
            'number' => '1',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $suiteId = DB::table('suites')->insertGetId([
            'building_id' => $buildingId,
            'floor_id' => $floorId,
            'number' => '101-S',
            'suiteStatus' => 0,
            'active' => 1,
        ]);

        DB::table('rooms')->insert([
            'building_id' => $buildingId,
            'floor_id' => $floorId,
            'suite_id' => $suiteId,
            'number' => '101',
            'room_type_id' => $roomTypeId,
            'capacity' => 2,
            'roomStatus' => 1,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Non-destructive: other migrations/seeders may have added more
        // property data on top of this baseline by the time down() runs,
        // and this is only ever meant to guarantee a floor exists, not to
        // track exactly what it created.
    }
};
