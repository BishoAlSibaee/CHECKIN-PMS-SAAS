<?php

namespace Tests\Feature\PosCharge;

use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Hotel;
use App\Models\JournalEntry;
use App\Models\PosCategory;
use App\Models\PosCharge;
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
 * POS charges (restaurant/cafe/laundry/minibar). Uses a fully self-contained,
 * uniquely-named hotel/building/room-type/room per test run (same reasoning
 * as GroupBookingTest/PerRoomCancelTest/CompanyBillingTest — this DB is real
 * and never reset between test runs).
 */
class PosChargeTest extends TestCase
{
    /** @return array{0: int, 1: Room, 2: int, 3: int, 4: User, 5: PosCategory} */
    private function seedFixtures(): array
    {
        $this->artisan('db:seed', [
            '--class' => ChartOfAccountsSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $marker = 'Pos Charge Test '.uniqid();

        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $building = \App\Models\Building::create(['hotel_id' => $hotel->id, 'number' => 'B1', 'name' => $marker]);
        $floor = \App\Models\Floor::create(['building_id' => $building->id, 'number' => '1', 'active' => 1]);

        $roomType = RoomType::create([
            'name_ar' => $marker,
            'name_en' => $marker,
            'description' => 'POS charge test fixture',
            'Min_daily_price' => 200,
            'Max_daily_price' => 300,
            'Min_monthly_price' => 4000,
            'Max_monthly_price' => 6000,
            'active_type' => 0,
        ]);

        $room = Room::unguarded(fn () => Room::create([
            'number' => 'POS'.substr((string) microtime(true), -6).random_int(10, 99),
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

        $account = ChartOfAccount::where('code', '4030')->firstOrFail();
        $category = PosCategory::create([
            'hotel_id' => null,
            'account_id' => $account->id,
            'name_en' => 'Restaurant '.uniqid(),
            'name_ar' => 'مطعم',
            'is_active' => true,
        ]);

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($hotel->id);

        $perms = [
            'view reservations', 'create reservations', 'update reservations', 'cancel reservations',
            'view pos charges', 'create pos charges', 'void pos charges',
            'view pos categories', 'manage pos categories',
        ];
        foreach ($perms as $perm) {
            Permission::findOrCreate($perm, 'api');
        }
        $role = Role::create(['name' => 'pos-test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->syncPermissions($perms);

        $user = User::create([
            'name' => 'Pos Staff '.uniqid(),
            'job_number' => 'POS-'.uniqid(),
            'email' => 'pos.'.uniqid().'@example.com',
            'mobile' => '05'.substr(uniqid(), -8),
            'active' => 1,
            'password' => bcrypt('test-password'),
        ]);
        $user->hotels()->syncWithoutDetaching([$hotel->id]);
        $user->assignRole($role);

        $registrar->setPermissionsTeamId($previousTeam);

        return [(int) $hotel->id, $room, (int) $stayReason->id, (int) $source->id, $user, $category];
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

    /** @return Reservation */
    private function makeInHouseReservation(int $hotelId, Room $room, int $stayReasonId, int $sourceId, User $user, string $start = '2027-08-10', string $end = '2027-08-12')
    {
        $client = $this->makeClient();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/makeReservation', [
                'client_id' => $client->id,
                'rooms' => [['room_id' => $room->id]],
                'start_date' => $start,
                'expire_date' => $end,
                'reservation_type' => 0,
                'reservation_status' => Reservation::STATUS_CONFIRMED,
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ]);

        $response->assertOk();
        $reservationId = $response->json('data.reservation.id');

        Reservation::where('id', $reservationId)->update(['logedin' => Reservation::LOGEDIN_IN_HOUSE]);

        return Reservation::findOrFail($reservationId);
    }

    public function test_charged_to_room_pos_charge_raises_balance_and_blocks_checkout(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $category] = $this->seedFixtures();
        $reservation = $this->makeInHouseReservation($hotelId, $room, $stayReasonId, $sourceId, $user);
        $balanceBefore = $reservation->balanceDue();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/pos-charges', [
                'pos_category_id' => $category->id,
                'description' => 'Dinner',
                'quantity' => 2,
                'unit_price' => 50,
                'payment_status' => \App\Models\PosCharge::PAYMENT_STATUS_CHARGED_TO_ROOM,
                'reservation_id' => $reservation->id,
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $reservation->refresh();
        $expectedTotal = round(100 * 1.15, 2);
        $this->assertEqualsWithDelta($balanceBefore + $expectedTotal, $reservation->balanceDue(), 0.02);

        // Checkout must be blocked while the POS charge is unpaid.
        $checkoutResponse = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->patchJson("/api/users/reservations/{$reservation->id}", ['logedin' => 0]);
        $checkoutResponse->assertStatus(422);
    }

    public function test_invoice_reference_is_stored_and_returned(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $category] = $this->seedFixtures();
        $reservation = $this->makeInHouseReservation($hotelId, $room, $stayReasonId, $sourceId, $user);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/pos-charges', [
                'pos_category_id' => $category->id,
                'quantity' => 1,
                'unit_price' => 50,
                'payment_status' => \App\Models\PosCharge::PAYMENT_STATUS_CHARGED_TO_ROOM,
                'reservation_id' => $reservation->id,
                'invoice_reference' => 'INV-4821',
            ]);

        $response->assertOk();
        $this->assertSame('INV-4821', $response->json('data.invoice_reference'));

        $chargeId = $response->json('data.id');
        $charge = PosCharge::findOrFail($chargeId);
        $this->assertSame('INV-4821', $charge->invoice_reference);
    }

    public function test_journal_entry_debits_guest_ar_and_credits_category_account(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $category] = $this->seedFixtures();
        $reservation = $this->makeInHouseReservation($hotelId, $room, $stayReasonId, $sourceId, $user);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/pos-charges', [
                'pos_category_id' => $category->id,
                'quantity' => 1,
                'unit_price' => 80,
                'payment_status' => \App\Models\PosCharge::PAYMENT_STATUS_CHARGED_TO_ROOM,
                'reservation_id' => $reservation->id,
            ]);

        $chargeId = $response->json('data.id');
        $entry = JournalEntry::where('reference', 'POS-CHARGE-'.$chargeId)->with('lines')->first();
        $this->assertNotNull($entry);

        $ar = ChartOfAccount::where('code', '1100')->first();
        $arLine = $entry->lines->firstWhere('account_id', $ar->id);
        $categoryLine = $entry->lines->firstWhere('account_id', $category->account_id);

        $this->assertNotNull($arLine);
        $this->assertEqualsWithDelta(92.0, (float) $arLine->debit, 0.02);
        $this->assertNotNull($categoryLine);
        $this->assertEqualsWithDelta(80.0, (float) $categoryLine->credit, 0.02);
    }

    public function test_paid_immediately_pos_charge_never_touches_reservation_and_debits_cash(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $category] = $this->seedFixtures();
        $reservation = $this->makeInHouseReservation($hotelId, $room, $stayReasonId, $sourceId, $user);
        $totalBefore = (float) $reservation->total;
        $balanceBefore = $reservation->balanceDue();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/pos-charges', [
                'pos_category_id' => $category->id,
                'quantity' => 1,
                'unit_price' => 40,
                'payment_status' => \App\Models\PosCharge::PAYMENT_STATUS_PAID_IMMEDIATELY,
                'reservation_id' => $reservation->id,
            ]);

        $response->assertOk();
        $chargeId = $response->json('data.id');

        $reservation->refresh();
        $this->assertEqualsWithDelta($totalBefore, (float) $reservation->total, 0.02);
        $this->assertEqualsWithDelta($balanceBefore, $reservation->balanceDue(), 0.02);

        $entry = JournalEntry::where('reference', 'POS-CHARGE-'.$chargeId)->with('lines')->first();
        $cash = ChartOfAccount::where('code', '1010')->first();
        $cashLine = $entry->lines->firstWhere('account_id', $cash->id);
        $this->assertNotNull($cashLine);
        $this->assertGreaterThan(0, (float) $cashLine->debit);
    }

    public function test_voiding_reverses_journal_entry_and_room_balance(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $category] = $this->seedFixtures();
        $reservation = $this->makeInHouseReservation($hotelId, $room, $stayReasonId, $sourceId, $user);
        $balanceBefore = $reservation->balanceDue();

        $createResponse = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/pos-charges', [
                'pos_category_id' => $category->id,
                'quantity' => 1,
                'unit_price' => 60,
                'payment_status' => \App\Models\PosCharge::PAYMENT_STATUS_CHARGED_TO_ROOM,
                'reservation_id' => $reservation->id,
            ]);
        $chargeId = $createResponse->json('data.id');

        $voidResponse = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/pos-charges/{$chargeId}/void");
        $voidResponse->assertOk();

        $reservation->refresh();
        $this->assertEqualsWithDelta($balanceBefore, $reservation->balanceDue(), 0.02);

        $reversal = JournalEntry::where('reference', 'POS-CHARGE-VOID-'.$chargeId)->with('lines')->first();
        $this->assertNotNull($reversal);

        $charge = PosCharge::findOrFail($chargeId);
        $this->assertSame(PosCharge::STATUS_VOIDED, (int) $charge->status);
    }

    public function test_walkin_charge_without_reservation_works_and_charging_to_room_requires_reservation(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $category] = $this->seedFixtures();

        $walkinResponse = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/pos-charges', [
                'pos_category_id' => $category->id,
                'quantity' => 1,
                'unit_price' => 25,
                'payment_status' => \App\Models\PosCharge::PAYMENT_STATUS_PAID_IMMEDIATELY,
                'walkin_reference' => 'Table 4',
            ]);
        $walkinResponse->assertOk();
        $this->assertNull($walkinResponse->json('data.reservation_id'));

        $rejectedResponse = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/pos-charges', [
                'pos_category_id' => $category->id,
                'quantity' => 1,
                'unit_price' => 25,
                'payment_status' => \App\Models\PosCharge::PAYMENT_STATUS_CHARGED_TO_ROOM,
            ]);
        $rejectedResponse->assertStatus(422);
    }

    public function test_pos_charge_survives_totals_recalculation_drift(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $category] = $this->seedFixtures();
        $reservation = $this->makeInHouseReservation($hotelId, $room, $stayReasonId, $sourceId, $user, '2027-08-10', '2027-08-12');

        $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/pos-charges', [
                'pos_category_id' => $category->id,
                'quantity' => 1,
                'unit_price' => 100,
                'payment_status' => \App\Models\PosCharge::PAYMENT_STATUS_CHARGED_TO_ROOM,
                'reservation_id' => $reservation->id,
            ])->assertOk();

        // Trigger a full totals recalculation via a date change (extend by one
        // night) — recalculateReservationPricing()/syncTotalsFromDailyCharges()
        // fully overwrite subtotal/taxes/total from the room price alone; this
        // used to silently wipe the POS charge's contribution.
        $updateResponse = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->patchJson("/api/users/reservations/{$reservation->id}", ['expire_date' => '2027-08-13']);
        $updateResponse->assertOk();

        $reservation->refresh();

        $roomOnlyBase = (float) \App\Models\ReservationDailyCharge::where('reservation_id', $reservation->id)->sum('base_amount');
        $expectedRoomSubtotal = round(
            $roomOnlyBase - (float) $reservation->classification_discount - (float) $reservation->discount
                + (float) $reservation->extras + (float) $reservation->penalties,
            2
        );
        $expectedRoomTotal = round($expectedRoomSubtotal * 1.15, 2);
        $posTotal = round(100 * 1.15, 2);

        $this->assertEqualsWithDelta($expectedRoomTotal + $posTotal, (float) $reservation->total, 0.05);
    }

    public function test_other_reservations_are_unaffected(): void
    {
        [$hotelId, $room, $stayReasonId, $sourceId, $user, $category] = $this->seedFixtures();
        $reservationA = $this->makeInHouseReservation($hotelId, $room, $stayReasonId, $sourceId, $user, '2027-08-20', '2027-08-22');

        $room2 = Room::unguarded(fn () => Room::create([
            'number' => 'POS'.substr((string) microtime(true), -6).random_int(10, 99),
            'building_id' => $room->building_id,
            'floor_id' => $room->floor_id,
            'suite_id' => null,
            'room_type_id' => $room->room_type_id,
            'capacity' => 2,
            'active' => 1,
            'roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE,
        ]));
        $reservationB = $this->makeInHouseReservation($hotelId, $room2, $stayReasonId, $sourceId, $user, '2027-08-20', '2027-08-22');
        $totalBBefore = (float) $reservationB->total;

        $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/pos-charges', [
                'pos_category_id' => $category->id,
                'quantity' => 1,
                'unit_price' => 100,
                'payment_status' => \App\Models\PosCharge::PAYMENT_STATUS_CHARGED_TO_ROOM,
                'reservation_id' => $reservationA->id,
            ])->assertOk();

        $reservationB->refresh();
        $this->assertEqualsWithDelta($totalBBefore, (float) $reservationB->total, 0.02);
    }
}
