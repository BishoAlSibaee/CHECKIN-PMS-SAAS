<?php

namespace Tests\Feature\Company;

use App\Models\Client;
use App\Models\Company;
use App\Models\Discount;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationSource;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay_reason;
use App\Models\User;
use App\Services\ReservationRoomStatusService;
use Database\Seeders\ChartOfAccountsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for real, pre-existing contamination bugs found while
 * building corporate billing: several cash/earnings aggregations had NO
 * filter on reservation_pay.type at all, so a non-cash
 * TYPE_BILLED_TO_COMPANY row would have been silently counted (or, in
 * FinancialDashboardService's binary branch, miscounted as a phantom
 * refund). These tests prove the fix holds through the real HTTP endpoints,
 * not just by reading the source.
 */
class CompanyBillingCashReportTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    /** @return array{0: int, 1: Room, 2: int, 3: int, 4: User, 5: Discount} */
    private function seedFixtures(): array
    {
        $this->artisan('db:seed', [
            '--class' => ChartOfAccountsSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $marker = 'Cash Report Test '.uniqid();

        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $building = \App\Models\Building::create(['hotel_id' => $hotel->id, 'number' => 'B1', 'name' => $marker]);
        $floor = \App\Models\Floor::create(['building_id' => $building->id, 'number' => '1', 'active' => 1]);

        $roomType = RoomType::create([
            'name_ar' => $marker,
            'name_en' => $marker,
            'description' => 'Cash report test fixture',
            'Min_daily_price' => 200,
            'Max_daily_price' => 300,
            'Min_monthly_price' => 4000,
            'Max_monthly_price' => 6000,
            'active_type' => 0,
        ]);

        $room = Room::unguarded(fn () => Room::create([
            'number' => 'CR'.substr((string) microtime(true), -6).random_int(10, 99),
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

        $discount = Discount::create([
            'name' => 'Cash Report Discount '.uniqid(),
            'is_percentage' => 1,
            'percent' => 10,
            'is_fixed' => 0,
            'fixed_amount' => 0,
            'is_active' => 1,
        ]);

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($hotel->id);

        $perms = ['create company reservations', 'view reservations', 'create reservations', 'view earnings', 'view payments'];
        foreach ($perms as $perm) {
            Permission::findOrCreate($perm, 'api');
        }
        $role = Role::create(['name' => 'cash-report-test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->syncPermissions($perms);

        $user = User::create([
            'name' => 'Cash Report Staff '.uniqid(),
            'job_number' => 'CR-'.uniqid(),
            'email' => 'cr.'.uniqid().'@example.com',
            'mobile' => '05'.substr(uniqid(), -8),
            'active' => 1,
            'password' => bcrypt('test-password'),
        ]);
        $user->hotels()->syncWithoutDetaching([$hotel->id]);
        $user->assignRole($role);

        $registrar->setPermissionsTeamId($previousTeam);

        return [(int) $hotel->id, $room, (int) $stayReason->id, (int) $source->id, $user, $discount];
    }

    private function makeClient(): Client
    {
        return Client::create([
            'first_name' => 'Guest',
            'last_name' => uniqid(),
            'email' => 'guest.'.uniqid().'@example.com',
            'international_code' => '+966',
            'mobile' => '05'.substr(uniqid(), -8),
            'IdType' => 'PASSPORT',
            'IdNumber' => 'TEST-'.uniqid(),
            'gender' => 'MALE',
            'guest_type' => 'VISITOR',
        ]);
    }

    public function test_company_billed_payment_is_excluded_from_the_earnings_all_tab(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $discount] = $this->seedFixtures();

        $company = Company::create([
            'hotel_id' => $hotelId,
            'name' => 'Cash Report Corp '.uniqid(),
            'discount_id' => $discount->id,
            'status' => Company::STATUS_ACTIVE,
            'created_by' => $user->id,
        ]);
        $client = $this->makeClient();

        $create = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/reservations/company-billed', [
                'company_id' => $company->id,
                'client_id' => $client->id,
                'rooms' => [['room_id' => $room->id]],
                'start_date' => '2027-11-01',
                'expire_date' => '2027-11-03',
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ]);
        $reservationId = $create->json('data.id');
        $this->assertNotNull($reservationId);

        $today = now()->toDateString();
        $list = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->getJson('/api/users/earnings-list?'.http_build_query([
                'start_date' => $today,
                'end_date' => $today,
                'per_page' => 200,
            ]));

        $list->assertOk();
        $rows = collect($list->json('data') ?? []);
        $match = $rows->firstWhere('reservation_id', $reservationId);

        $this->assertNull($match, 'The non-cash company-billing row must not appear in the earnings "all" tab.');
    }

    public function test_company_billed_payment_does_not_inflate_earnings_summary_cash_in(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $discount] = $this->seedFixtures();

        $company = Company::create([
            'hotel_id' => $hotelId,
            'name' => 'Cash Report Corp '.uniqid(),
            'discount_id' => $discount->id,
            'status' => Company::STATUS_ACTIVE,
            'created_by' => $user->id,
        ]);
        $client = $this->makeClient();

        $today = now()->toDateString();

        $before = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->getJson('/api/users/earnings-summary?'.http_build_query(['start_date' => $today, 'end_date' => $today]));
        $before->assertOk();
        $totalInBefore = (float) ($before->json('data.total_in') ?? $before->json('data.in') ?? 0);

        $create = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/reservations/company-billed', [
                'company_id' => $company->id,
                'client_id' => $client->id,
                'rooms' => [['room_id' => $room->id]],
                'start_date' => '2027-11-05',
                'expire_date' => '2027-11-07',
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ]);
        $create->assertOk();

        $after = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->getJson('/api/users/earnings-summary?'.http_build_query(['start_date' => $today, 'end_date' => $today]));
        $after->assertOk();
        $totalInAfter = (float) ($after->json('data.total_in') ?? $after->json('data.in') ?? 0);

        $this->assertEqualsWithDelta(
            $totalInBefore,
            $totalInAfter,
            0.02,
            'Company-billed recognition must not change real cash-in totals for the day.'
        );
    }

    /**
     * Regression for a second, opposite-shaped contamination bug: the
     * collections "outstanding balance" aggregation (dashboard banner +
     * Collections tab) only counted TYPE_PAYMENT/TYPE_REFUND as settled, so a
     * company-billed reservation — whose guest balance is actually zero —
     * was counted as if the guest still owed the full total.
     */
    public function test_company_billed_reservation_does_not_appear_as_outstanding(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $discount] = $this->seedFixtures();

        $company = Company::create([
            'hotel_id' => $hotelId,
            'name' => 'Cash Report Corp '.uniqid(),
            'discount_id' => $discount->id,
            'status' => Company::STATUS_ACTIVE,
            'created_by' => $user->id,
        ]);
        $client = $this->makeClient();

        $before = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->getJson('/api/users/collections/summary');
        $before->assertOk();
        $balanceBefore = (float) $before->json('data.total_balance');
        $countBefore = (int) $before->json('data.count');

        $create = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/reservations/company-billed', [
                'company_id' => $company->id,
                'client_id' => $client->id,
                'rooms' => [['room_id' => $room->id]],
                'start_date' => '2027-11-10',
                'expire_date' => '2027-11-12',
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ]);
        $create->assertOk();
        $reservationId = $create->json('data.id');

        $after = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->getJson('/api/users/collections/summary');
        $after->assertOk();

        $this->assertEqualsWithDelta(
            $balanceBefore,
            (float) $after->json('data.total_balance'),
            0.02,
            'A company-billed reservation must not inflate the outstanding-balance total — the guest owes nothing.'
        );
        $this->assertSame(
            $countBefore,
            (int) $after->json('data.count'),
            'A company-billed reservation must not be counted as an outstanding reservation.'
        );

        $list = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->getJson('/api/users/collections?'.http_build_query(['tab' => 'all', 'per_page' => 200]));
        $list->assertOk();
        $rows = collect($list->json('data.items') ?? $list->json('data') ?? []);
        $match = $rows->firstWhere('reservation_id', $reservationId);

        $this->assertNull($match, 'The company-billed reservation must not appear in the Collections outstanding list.');
    }
}
