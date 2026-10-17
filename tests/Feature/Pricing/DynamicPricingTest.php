<?php

namespace Tests\Feature\Pricing;

use App\Models\Client;
use App\Models\DynamicPricingRule;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationSource;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay_reason;
use App\Models\User;
use App\Services\PricingEngine;
use App\Services\ReservationRoomStatusService;
use Carbon\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Dynamic pricing (occupancy% + lead-time% adjustments, per room type).
 * All fixtures use active_type=2 (always Max_daily_price) so the "base"
 * rate is fully predictable regardless of the shared peak_days seed data.
 */
class DynamicPricingTest extends TestCase
{
    /** @return array{0: int, 1: RoomType, 2: Room, 3: int, 4: int, 5: User} */
    private function seedFixtures(float $minDaily = 100, float $maxDaily = 200): array
    {
        $marker = 'Dynamic Pricing Test '.uniqid();

        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $building = \App\Models\Building::create(['hotel_id' => $hotel->id, 'number' => 'B1', 'name' => $marker]);
        $floor = \App\Models\Floor::create(['building_id' => $building->id, 'number' => '1', 'active' => 1]);

        $roomType = RoomType::create([
            'name_ar' => $marker,
            'name_en' => $marker,
            'description' => 'Dynamic pricing test fixture',
            'Min_daily_price' => $minDaily,
            'Max_daily_price' => $maxDaily,
            'Min_monthly_price' => $minDaily * 20,
            'Max_monthly_price' => $maxDaily * 20,
            'active_type' => 2,
            'dynamic_pricing_enabled' => false,
        ]);

        $room = Room::unguarded(fn () => Room::create([
            'number' => 'DP'.substr((string) microtime(true), -6).random_int(10, 99),
            'building_id' => $building->id,
            'floor_id' => $floor->id,
            'suite_id' => null,
            'room_type_id' => $roomType->id,
            'capacity' => 2,
            'active' => 1,
            'roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE,
        ]));

        $stayReason = Stay_reason::firstOrCreate(
            ['name_en' => 'Tourism'],
            ['name_ar' => 'سياحة', 'description' => 'Test stay reason']
        );
        $source = ReservationSource::create(['name_ar' => $marker, 'name_en' => $marker, 'description' => 'test']);

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($hotel->id);

        $perms = ['view reservations', 'create reservations'];
        foreach ($perms as $perm) {
            Permission::findOrCreate($perm, 'api');
        }
        $role = Role::create(['name' => 'dp-test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->syncPermissions($perms);

        $user = User::create([
            'name' => 'DP Staff '.uniqid(),
            'job_number' => 'DP-'.uniqid(),
            'email' => 'dp.'.uniqid().'@example.com',
            'mobile' => '05'.substr(uniqid(), -8),
            'active' => 1,
            'password' => bcrypt('test-password'),
        ]);
        $user->hotels()->syncWithoutDetaching([$hotel->id]);
        $user->assignRole($role);

        $registrar->setPermissionsTeamId($previousTeam);

        return [(int) $hotel->id, $roomType, $room, (int) $stayReason->id, (int) $source->id, $user];
    }

    private function addRule(RoomType $roomType, string $metric, int $from, ?int $to, float $percent): DynamicPricingRule
    {
        return $roomType->dynamicPricingRules()->create([
            'metric' => $metric,
            'range_from' => $from,
            'range_to' => $to,
            'adjustment_percent' => $percent,
        ]);
    }

    public function test_disabled_room_type_keeps_existing_plan_based_pricing(): void
    {
        [, $roomType, $room] = $this->seedFixtures(100, 200);

        $start = Carbon::today()->addDays(10);
        $end = $start->copy()->addDays(2);

        $plan = \App\Models\Pricingplan::create([
            'NameAr' => 'خطة', 'NameEn' => 'Plan', 'StartDate' => $start->toDateString(), 'EndDate' => $end->toDateString(), 'ActiveType' => 1,
        ]);
        \App\Models\RoomtypePricingplan::create([
            'roomtype_id' => $roomType->id, 'pricingplan_id' => $plan->id, 'DailyPrice' => 999, 'MonthlyPrice' => 999 * 20,
        ]);

        $lines = app(PricingEngine::class)->buildDailyBreakdown($room, $start->toDateString(), $end->toDateString(), 0, 0);

        foreach ($lines as $line) {
            $this->assertEqualsWithDelta(999.0, $line['base_amount'], 0.02);
            $this->assertSame('plan', $line['price_source']);
        }
    }

    public function test_enabled_room_type_ignores_the_plan_and_uses_max_daily_price_with_no_rules(): void
    {
        [, $roomType, $room] = $this->seedFixtures(100, 200);
        $roomType->update(['dynamic_pricing_enabled' => true]);

        $start = Carbon::today()->addDays(10);
        $end = $start->copy()->addDays(2);

        $plan = \App\Models\Pricingplan::create([
            'NameAr' => 'خطة', 'NameEn' => 'Plan', 'StartDate' => $start->toDateString(), 'EndDate' => $end->toDateString(), 'ActiveType' => 1,
        ]);
        \App\Models\RoomtypePricingplan::create([
            'roomtype_id' => $roomType->id, 'pricingplan_id' => $plan->id, 'DailyPrice' => 999, 'MonthlyPrice' => 999 * 20,
        ]);

        $lines = app(PricingEngine::class)->buildDailyBreakdown($room, $start->toDateString(), $end->toDateString(), 0, 0);

        foreach ($lines as $line) {
            $this->assertEqualsWithDelta(200.0, $line['base_amount'], 0.02, 'Plan price (999) must never be used when dynamic pricing is enabled.');
            $this->assertSame('dynamic', $line['price_source']);
        }
    }

    public function test_occupancy_rule_increases_price_when_a_sibling_room_is_booked(): void
    {
        [$hotelId, $roomType, $roomA, $stayReasonId, $sourceId, $user] = $this->seedFixtures(100, 200);

        $roomB = Room::unguarded(fn () => Room::create([
            'number' => 'DP'.substr((string) microtime(true), -6).random_int(10, 99),
            'building_id' => $roomA->building_id,
            'floor_id' => $roomA->floor_id,
            'suite_id' => null,
            'room_type_id' => $roomType->id,
            'capacity' => 2,
            'active' => 1,
            'roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE,
        ]));

        $start = Carbon::today()->addDays(20);
        $end = $start->copy()->addDays(1);
        $client = Client::create([
            'first_name' => 'Guest', 'last_name' => uniqid(), 'email' => 'guest.'.uniqid().'@example.com',
            'international_code' => '+966', 'mobile' => '05'.substr(uniqid(), -8),
            'IdType' => 'PASSPORT', 'IdNumber' => 'TEST-'.uniqid(), 'gender' => 'MALE', 'guest_type' => 'VISITOR',
        ]);

        $bookRoomB = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/makeReservation', [
                'client_id' => $client->id,
                'rooms' => [['room_id' => $roomB->id]],
                'start_date' => $start->toDateString(),
                'expire_date' => $end->toDateString(),
                'reservation_type' => 0,
                'reservation_status' => Reservation::STATUS_CONFIRMED,
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ]);
        $bookRoomB->assertOk();

        $roomType->update(['dynamic_pricing_enabled' => true]);
        $this->addRule($roomType, DynamicPricingRule::METRIC_OCCUPANCY, 50, null, 20);

        $lines = app(PricingEngine::class)->buildDailyBreakdown($roomA, $start->toDateString(), $end->toDateString(), 0, 0);

        $this->assertEqualsWithDelta(240.0, $lines[0]['base_amount'], 0.02, '200 base * 1.20 (50% occupancy rule) = 240');
    }

    public function test_lead_time_rule_increases_price_for_a_near_term_stay(): void
    {
        [, $roomType, $room] = $this->seedFixtures(100, 200);
        $roomType->update(['dynamic_pricing_enabled' => true]);
        $this->addRule($roomType, DynamicPricingRule::METRIC_LEAD_TIME, 0, 10, 15);

        $start = Carbon::today()->addDays(5);
        $end = $start->copy()->addDays(1);

        $lines = app(PricingEngine::class)->buildDailyBreakdown($room, $start->toDateString(), $end->toDateString(), 0, 0);

        $this->assertEqualsWithDelta(230.0, $lines[0]['base_amount'], 0.02, '200 base * 1.15 (lead-time rule) = 230');
    }

    public function test_occupancy_and_lead_time_adjustments_sum_when_both_match(): void
    {
        [$hotelId, $roomType, $roomA, $stayReasonId, $sourceId, $user] = $this->seedFixtures(100, 200);

        $roomB = Room::unguarded(fn () => Room::create([
            'number' => 'DP'.substr((string) microtime(true), -6).random_int(10, 99),
            'building_id' => $roomA->building_id,
            'floor_id' => $roomA->floor_id,
            'suite_id' => null,
            'room_type_id' => $roomType->id,
            'capacity' => 2,
            'active' => 1,
            'roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE,
        ]));

        $start = Carbon::today()->addDays(5);
        $end = $start->copy()->addDays(1);
        $client = Client::create([
            'first_name' => 'Guest', 'last_name' => uniqid(), 'email' => 'guest.'.uniqid().'@example.com',
            'international_code' => '+966', 'mobile' => '05'.substr(uniqid(), -8),
            'IdType' => 'PASSPORT', 'IdNumber' => 'TEST-'.uniqid(), 'gender' => 'MALE', 'guest_type' => 'VISITOR',
        ]);

        $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/makeReservation', [
                'client_id' => $client->id,
                'rooms' => [['room_id' => $roomB->id]],
                'start_date' => $start->toDateString(),
                'expire_date' => $end->toDateString(),
                'reservation_type' => 0,
                'reservation_status' => Reservation::STATUS_CONFIRMED,
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ])->assertOk();

        $roomType->update(['dynamic_pricing_enabled' => true]);
        $this->addRule($roomType, DynamicPricingRule::METRIC_OCCUPANCY, 50, null, 20);
        $this->addRule($roomType, DynamicPricingRule::METRIC_LEAD_TIME, 0, 10, 15);

        $lines = app(PricingEngine::class)->buildDailyBreakdown($roomA, $start->toDateString(), $end->toDateString(), 0, 0);

        $this->assertEqualsWithDelta(270.0, $lines[0]['base_amount'], 0.02, '200 base * 1.35 (20% + 15% summed) = 270');
    }

    public function test_extreme_rules_are_clamped_to_the_floor_and_ceiling(): void
    {
        [, $roomType, $room] = $this->seedFixtures(100, 200);
        $roomType->update(['dynamic_pricing_enabled' => true]);
        $this->addRule($roomType, DynamicPricingRule::METRIC_OCCUPANCY, 0, null, 500);

        $start = Carbon::today()->addDays(30);
        $end = $start->copy()->addDays(1);
        $lines = app(PricingEngine::class)->buildDailyBreakdown($room, $start->toDateString(), $end->toDateString(), 0, 0);
        $this->assertEqualsWithDelta(400.0, $lines[0]['base_amount'], 0.02, 'Ceiling = Max_daily_price * 2 = 400');

        DynamicPricingRule::query()->where('room_type_id', $roomType->id)->delete();
        $this->addRule($roomType, DynamicPricingRule::METRIC_OCCUPANCY, 0, null, -90);
        $lines2 = app(PricingEngine::class)->buildDailyBreakdown($room, $start->toDateString(), $end->toDateString(), 0, 0);
        $this->assertEqualsWithDelta(100.0, $lines2[0]['base_amount'], 0.02, 'Floor = Min_daily_price = 100');
    }

    public function test_monthly_rentals_are_unaffected_by_dynamic_pricing(): void
    {
        [, $roomType, $room] = $this->seedFixtures(100, 200);
        $roomType->update(['dynamic_pricing_enabled' => true]);
        $this->addRule($roomType, DynamicPricingRule::METRIC_OCCUPANCY, 0, null, 500);

        $start = Carbon::today()->addDays(1)->startOfMonth()->addMonth();
        $end = $start->copy()->addMonths(2);

        $lines = app(PricingEngine::class)->buildDailyBreakdown($room, $start->toDateString(), $end->toDateString(), 1, 0);

        foreach ($lines as $line) {
            $this->assertNotSame('dynamic', $line['price_source'], 'Monthly rentals must never go through the dynamic pricing path.');
        }
    }

    public function test_other_room_types_are_unaffected(): void
    {
        [, $roomTypeA, $roomA] = $this->seedFixtures(100, 200);
        [, $roomTypeB, $roomB] = $this->seedFixtures(50, 80);

        $roomTypeA->update(['dynamic_pricing_enabled' => true]);
        $this->addRule($roomTypeA, DynamicPricingRule::METRIC_OCCUPANCY, 0, null, 50);

        $start = Carbon::today()->addDays(40);
        $end = $start->copy()->addDays(1);

        $linesB = app(PricingEngine::class)->buildDailyBreakdown($roomB, $start->toDateString(), $end->toDateString(), 0, 0);

        $this->assertEqualsWithDelta(80.0, $linesB[0]['base_amount'], 0.02);
        $this->assertSame('max', $linesB[0]['price_source']);
    }
}
