<?php

namespace Tests\Feature\Company;

use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\Discount;
use App\Models\Hotel;
use App\Models\JournalEntry;
use App\Models\Reservation;
use App\Models\ReservationPay;
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
 * Corporate billing: a company owes the hotel, not the guest. Uses a fully
 * self-contained, uniquely-named hotel/building/room-type/room per test run
 * (same reasoning as GroupBookingTest/PerRoomCancelTest — this DB is real
 * and never reset between test runs).
 */
class CompanyBillingTest extends TestCase
{
    /** @return array{0: int, 1: Room, 2: int, 3: int, 4: User, 5: Discount} */
    private function seedFixtures(float $discountPercent = 10): array
    {
        $this->artisan('db:seed', [
            '--class' => ChartOfAccountsSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $marker = 'Company Billing Test '.uniqid();

        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $building = \App\Models\Building::create(['hotel_id' => $hotel->id, 'number' => 'B1', 'name' => $marker]);
        $floor = \App\Models\Floor::create(['building_id' => $building->id, 'number' => '1', 'active' => 1]);

        $roomType = RoomType::create([
            'name_ar' => $marker,
            'name_en' => $marker,
            'description' => 'Company billing test fixture',
            'Min_daily_price' => 200,
            'Max_daily_price' => 300,
            'Min_monthly_price' => 4000,
            'Max_monthly_price' => 6000,
            'active_type' => 0,
        ]);

        $room = Room::unguarded(fn () => Room::create([
            'number' => 'CB'.substr((string) microtime(true), -6).random_int(10, 99),
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
            'name' => 'Company Discount '.uniqid(),
            'is_percentage' => 1,
            'percent' => $discountPercent,
            'is_fixed' => 0,
            'fixed_amount' => 0,
            'is_active' => 1,
        ]);

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($hotel->id);

        $perms = ['view companies', 'manage companies', 'view company ledger', 'record company settlement', 'create company reservations', 'view reservations', 'update reservations', 'cancel reservations', 'manage refunds'];
        foreach ($perms as $perm) {
            Permission::findOrCreate($perm, 'api');
        }
        $role = Role::create(['name' => 'company-test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->syncPermissions($perms);

        $user = User::create([
            'name' => 'Company Staff '.uniqid(),
            'job_number' => 'CB-'.uniqid(),
            'email' => 'cb.'.uniqid().'@example.com',
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

    private function makeCompany(int $hotelId, int $discountId, int $userId, ?float $creditLimit = null): Company
    {
        return Company::create([
            'hotel_id' => $hotelId,
            'name' => 'Acme Corp '.uniqid(),
            'discount_id' => $discountId,
            'credit_limit' => $creditLimit,
            'status' => Company::STATUS_ACTIVE,
            'created_by' => $userId,
        ]);
    }

    public function test_creating_a_company_billed_reservation_zeroes_the_guest_balance(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $discount] = $this->seedFixtures();
        $company = $this->makeCompany($hotelId, $discount->id, $user->id);
        $client = $this->makeClient();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/reservations/company-billed', [
                'company_id' => $company->id,
                'client_id' => $client->id,
                'rooms' => [['room_id' => $room->id]],
                'start_date' => '2027-09-10',
                'expire_date' => '2027-09-12',
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $reservationId = $response->json('data.id');
        $reservation = Reservation::findOrFail($reservationId);

        $this->assertSame($company->id, $reservation->company_id);
        $this->assertEqualsWithDelta(0.0, $reservation->balanceDue(), 0.02);

        $payment = ReservationPay::where('reservation_id', $reservationId)
            ->where('type', ReservationPay::TYPE_BILLED_TO_COMPANY)
            ->first();
        $this->assertNotNull($payment);
        $this->assertEqualsWithDelta((float) $reservation->total, (float) $payment->pay, 0.02);
    }

    public function test_journal_entry_debits_corporate_ar_and_credits_guest_ar(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $discount] = $this->seedFixtures();
        $company = $this->makeCompany($hotelId, $discount->id, $user->id);
        $client = $this->makeClient();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/reservations/company-billed', [
                'company_id' => $company->id,
                'client_id' => $client->id,
                'rooms' => [['room_id' => $room->id]],
                'start_date' => '2027-09-15',
                'expire_date' => '2027-09-17',
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ]);

        $reservationId = $response->json('data.id');

        $entry = JournalEntry::where('reference', 'COMPANY-BILLING-'.$reservationId)->with('lines')->first();
        $this->assertNotNull($entry);

        $corporateAr = ChartOfAccount::where('code', '1160')->first();
        $guestAr = ChartOfAccount::where('code', '1100')->first();

        $corporateArLine = $entry->lines->firstWhere('account_id', $corporateAr->id);
        $guestArLine = $entry->lines->firstWhere('account_id', $guestAr->id);

        $this->assertNotNull($corporateArLine);
        $this->assertGreaterThan(0, (float) $corporateArLine->debit);
        $this->assertSame($company->id, $corporateArLine->company_id);

        $this->assertNotNull($guestArLine);
        $this->assertGreaterThan(0, (float) $guestArLine->credit);
        $this->assertEqualsWithDelta((float) $corporateArLine->debit, (float) $guestArLine->credit, 0.02);
    }

    public function test_discount_is_applied_from_the_companys_linked_discount(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $discount] = $this->seedFixtures(20);
        $company = $this->makeCompany($hotelId, $discount->id, $user->id);
        $client = $this->makeClient();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/reservations/company-billed', [
                'company_id' => $company->id,
                'client_id' => $client->id,
                'rooms' => [['room_id' => $room->id]],
                'start_date' => '2027-09-20',
                'expire_date' => '2027-09-22',
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ]);

        $reservationId = $response->json('data.id');
        $reservation = Reservation::findOrFail($reservationId);

        $this->assertEqualsWithDelta(round($reservation->base_price * 0.20, 2), (float) $reservation->discount, 0.05);
    }

    public function test_checkout_succeeds_for_a_company_billed_reservation(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $discount] = $this->seedFixtures();
        $company = $this->makeCompany($hotelId, $discount->id, $user->id);
        $client = $this->makeClient();

        \Carbon\Carbon::setTestNow('2027-09-10');

        $create = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/reservations/company-billed', [
                'company_id' => $company->id,
                'client_id' => $client->id,
                'rooms' => [['room_id' => $room->id]],
                'start_date' => '2027-09-10',
                'expire_date' => '2027-09-12',
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
                'logedin' => 1,
            ]);
        $reservationId = $create->json('data.id');

        // This is the exact bug the feature fixes: without counting
        // TYPE_BILLED_TO_COMPANY in paidNetAmount(), this checkout would be
        // rejected with "Outstanding balance must be paid before check-out."
        $checkout = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->patchJson("/api/users/reservations/{$reservationId}", ['logedin' => 0]);

        \Carbon\Carbon::setTestNow();

        $checkout->assertOk();
        $checkout->assertJsonPath('success', true);
    }

    public function test_booking_beyond_credit_limit_is_rejected(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $discount] = $this->seedFixtures();
        $company = $this->makeCompany($hotelId, $discount->id, $user->id, 10.0);
        $client = $this->makeClient();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/reservations/company-billed', [
                'company_id' => $company->id,
                'client_id' => $client->id,
                'rooms' => [['room_id' => $room->id]],
                'start_date' => '2027-09-25',
                'expire_date' => '2027-09-27',
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ]);

        $response->assertJsonPath('success', false);
        $this->assertSame(0, Reservation::where('company_id', $company->id)->count());
    }

    public function test_cancelling_a_company_billed_reservation_reverses_the_journal_entry_without_touching_cash(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $discount] = $this->seedFixtures();
        $company = $this->makeCompany($hotelId, $discount->id, $user->id);
        $client = $this->makeClient();

        $create = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/reservations/company-billed', [
                'company_id' => $company->id,
                'client_id' => $client->id,
                'rooms' => [['room_id' => $room->id]],
                'start_date' => '2027-10-01',
                'expire_date' => '2027-10-03',
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ]);
        $reservationId = $create->json('data.id');

        $cancel = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservationId}/cancel", ['reason' => 'Trip cancelled']);

        $cancel->assertOk();

        $reversal = JournalEntry::where('reference', 'COMPANY-BILLING-REVERSAL-'.$reservationId)->first();
        $this->assertNotNull($reversal);

        // No refund row, no cash movement — the reversal is journal-only.
        $this->assertSame(
            0,
            ReservationPay::where('reservation_id', $reservationId)->where('type', ReservationPay::TYPE_REFUND)->count()
        );
    }

    public function test_normal_refund_endpoint_refuses_a_company_billed_reservation(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $discount] = $this->seedFixtures();
        $company = $this->makeCompany($hotelId, $discount->id, $user->id);
        $client = $this->makeClient();

        $create = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/reservations/company-billed', [
                'company_id' => $company->id,
                'client_id' => $client->id,
                'rooms' => [['room_id' => $room->id]],
                'start_date' => '2027-10-05',
                'expire_date' => '2027-10-07',
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ]);
        $reservationId = $create->json('data.id');

        $refund = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/refund', ['reservation_id' => $reservationId]);

        $refund->assertJsonPath('success', false);
    }

    public function test_company_ledger_statement_reflects_the_reservation_balance(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $discount] = $this->seedFixtures();
        $company = $this->makeCompany($hotelId, $discount->id, $user->id);
        $client = $this->makeClient();

        $create = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/reservations/company-billed', [
                'company_id' => $company->id,
                'client_id' => $client->id,
                'rooms' => [['room_id' => $room->id]],
                'start_date' => '2027-10-10',
                'expire_date' => '2027-10-12',
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ]);
        $reservation = Reservation::findOrFail($create->json('data.id'));

        $statement = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->getJson("/api/users/companies-ledger/{$company->id}");

        $statement->assertOk();
        $this->assertEqualsWithDelta((float) $reservation->total, (float) $statement->json('data.balance'), 0.05);
    }

    public function test_settlement_reduces_the_company_balance_and_rejects_overpayment(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $discount] = $this->seedFixtures();
        $company = $this->makeCompany($hotelId, $discount->id, $user->id);
        $client = $this->makeClient();

        $create = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/reservations/company-billed', [
                'company_id' => $company->id,
                'client_id' => $client->id,
                'rooms' => [['room_id' => $room->id]],
                'start_date' => '2027-10-15',
                'expire_date' => '2027-10-17',
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ]);
        $total = (float) Reservation::findOrFail($create->json('data.id'))->total;

        $overpay = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/companies-ledger/{$company->id}/settle", ['amount' => $total + 100]);
        $overpay->assertStatus(422);

        $settle = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/companies-ledger/{$company->id}/settle", ['amount' => $total]);
        $settle->assertOk();

        $statement = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->getJson("/api/users/companies-ledger/{$company->id}");
        $this->assertEqualsWithDelta(0.0, (float) $statement->json('data.balance'), 0.05);
    }

    public function test_no_loyalty_points_awarded_for_company_billing(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $discount] = $this->seedFixtures();
        $company = $this->makeCompany($hotelId, $discount->id, $user->id);
        $client = $this->makeClient();

        $create = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/reservations/company-billed', [
                'company_id' => $company->id,
                'client_id' => $client->id,
                'rooms' => [['room_id' => $room->id]],
                'start_date' => '2027-10-20',
                'expire_date' => '2027-10-22',
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ]);
        $create->assertOk();

        $client->refresh();
        $this->assertEqualsWithDelta(0.0, (float) ($client->loyalty_points_balance ?? 0), 0.01);
    }
}
