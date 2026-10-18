<?php

namespace Tests\Feature\GroupBooking;

use App\Models\Client;
use App\Models\GroupBooking;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationSource;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay_reason;
use App\Models\User;
use App\Services\ReservationRoomStatusService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A group/event spans multiple independent Reservation rows (different
 * clients, different dates) — see the group-bookings plan. Uses a fully
 * self-contained, uniquely-named hotel/building/room-type/rooms per test
 * run, never reused across tests, for the same reason
 * ChannexBookingIngestionTest does: this DB is real and never reset between
 * test runs, so ambiguous "first room type in the table"-style fixtures
 * collide with unrelated tests over time.
 */
class GroupBookingTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    /** @return array{0: int, 1: RoomType, 2: Room, 3: Room, 4: int, 5: int, 6: User} */
    private function seedGroupBookingFixtures(): array
    {
        $marker = 'Group Booking Test '.uniqid();

        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $building = \App\Models\Building::create(['hotel_id' => $hotel->id, 'number' => 'B1', 'name' => $marker]);
        $floor = \App\Models\Floor::create(['building_id' => $building->id, 'number' => '1', 'active' => 1]);

        $roomType = RoomType::create([
            'name_ar' => $marker,
            'name_en' => $marker,
            'description' => 'Group booking test fixture',
            'Min_daily_price' => 150,
            'Max_daily_price' => 250,
            'Min_monthly_price' => 3500,
            'Max_monthly_price' => 5500,
            'active_type' => 0,
        ]);

        $makeRoom = function () use ($building, $floor, $roomType) {
            return Room::unguarded(function () use ($building, $floor, $roomType) {
                return Room::create([
                    'number' => 'GB'.substr((string) microtime(true), -6).random_int(10, 99),
                    'building_id' => $building->id,
                    'floor_id' => $floor->id,
                    'suite_id' => null,
                    'room_type_id' => $roomType->id,
                    'capacity' => 2,
                    'active' => 1,
                    'roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE,
                ]);
            });
        };
        $roomA = $makeRoom();
        $roomB = $makeRoom();

        $stayReason = Stay_reason::firstOrCreate(
            ['name_en' => 'Tourism'],
            ['name_ar' => 'سياحة', 'description' => 'Test stay reason']
        );

        $source = ReservationSource::create([
            'name_ar' => $marker,
            'name_en' => $marker,
            'description' => 'Group booking test source',
        ]);

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($hotel->id);

        foreach (['view group bookings', 'create group bookings', 'cancel group bookings', 'view reservations', 'create reservations', 'cancel reservations'] as $perm) {
            Permission::findOrCreate($perm, 'api');
        }
        $role = Role::create(['name' => 'group-test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->syncPermissions(['view group bookings', 'create group bookings', 'cancel group bookings', 'view reservations', 'create reservations', 'cancel reservations']);

        $user = User::create([
            'name' => 'Group Staff '.uniqid(),
            'job_number' => 'GRP-'.uniqid(),
            'email' => 'group.'.uniqid().'@example.com',
            'mobile' => '05'.substr(uniqid(), -8),
            'active' => 1,
            'password' => bcrypt('test-password'),
        ]);
        $user->hotels()->syncWithoutDetaching([$hotel->id]);
        $user->assignRole($role);

        $registrar->setPermissionsTeamId($previousTeam);

        return [(int) $hotel->id, $roomType, $roomA, $roomB, (int) $stayReason->id, (int) $source->id, $user];
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

    /**
     * reservation_status defaults to CONFIRMED — a staff-created group/event
     * booking is a real commitment, not a tentative pending-payment hold
     * (mirrors ChannexBookingIngestionService's own reasoning for real
     * bookings); pending-payment reservations are explicitly non-blocking
     * inventory (Reservation::nonBlockingInventoryStatuses()), so leaving
     * this unset would make every member "available" regardless of overlap.
     *
     * @return array<string, mixed>
     */
    private function memberDraft(Room $room, Client $client, int $stayReasonId, int $sourceId, string $start, string $end): array
    {
        return [
            'client_id' => $client->id,
            'rooms' => [['room_id' => $room->id]],
            'start_date' => $start,
            'expire_date' => $end,
            'stay_reason_id' => $stayReasonId,
            'reservation_source_id' => $sourceId,
            'rent_type' => 0,
            'price_calculation_mode' => 0,
            'reservation_status' => Reservation::STATUS_CONFIRMED,
        ];
    }

    public function test_create_group_with_multiple_members_links_every_reservation_to_the_group(): void
    {
        [$hotelId, , $roomA, $roomB, $stayReasonId, $sourceId, $user] = $this->seedGroupBookingFixtures();
        $clientA = $this->makeClient();
        $clientB = $this->makeClient();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/group-bookings', [
                'name' => 'Test Wedding Event',
                'company_name' => 'Test Co',
                'group_discount_percent' => 10,
                'members' => [
                    $this->memberDraft($roomA, $clientA, $stayReasonId, $sourceId, '2027-01-10', '2027-01-12'),
                    $this->memberDraft($roomB, $clientB, $stayReasonId, $sourceId, '2027-01-10', '2027-01-13'),
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $groupId = $response->json('data.group.id');
        $this->assertNotNull($groupId);

        $memberReservationIds = Reservation::where('group_booking_id', $groupId)->pluck('id');
        $this->assertCount(2, $memberReservationIds);

        foreach (Reservation::whereIn('id', $memberReservationIds)->get() as $reservation) {
            // 10% group discount must be reflected against that member's own base_price.
            $this->assertEqualsWithDelta(
                round(((float) $reservation->base_price) * 0.10, 2),
                round((float) $reservation->discount, 2),
                0.05
            );
        }
    }

    public function test_a_member_room_conflict_rolls_back_the_whole_group(): void
    {
        [$hotelId, , $roomA, , $stayReasonId, $sourceId, $user] = $this->seedGroupBookingFixtures();
        $clientA = $this->makeClient();
        $clientB = $this->makeClient();

        $groupCountBefore = GroupBooking::count();
        $reservationCountBefore = Reservation::count();

        // Both members target the SAME room on overlapping dates — the
        // second member's makeReservation() call must fail, rolling back
        // the first member's reservation and the group row too.
        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/group-bookings', [
                'name' => 'Conflict Test Event',
                'members' => [
                    $this->memberDraft($roomA, $clientA, $stayReasonId, $sourceId, '2027-02-10', '2027-02-12'),
                    $this->memberDraft($roomA, $clientB, $stayReasonId, $sourceId, '2027-02-11', '2027-02-13'),
                ],
            ]);

        $response->assertJsonPath('success', false);
        $this->assertSame($groupCountBefore, GroupBooking::count());
        $this->assertSame($reservationCountBefore, Reservation::count());
    }

    public function test_financials_aggregate_sums_every_member_reservation(): void
    {
        [$hotelId, , $roomA, $roomB, $stayReasonId, $sourceId, $user] = $this->seedGroupBookingFixtures();
        $clientA = $this->makeClient();
        $clientB = $this->makeClient();

        $create = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/group-bookings', [
                'name' => 'Financials Test Event',
                'members' => [
                    $this->memberDraft($roomA, $clientA, $stayReasonId, $sourceId, '2027-03-10', '2027-03-12'),
                    $this->memberDraft($roomB, $clientB, $stayReasonId, $sourceId, '2027-03-10', '2027-03-12'),
                ],
            ]);
        $groupId = $create->json('data.group.id');

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->getJson("/api/users/group-bookings/{$groupId}/financials");

        $response->assertOk();
        $expectedTotal = round(Reservation::where('group_booking_id', $groupId)->sum('total'), 2);
        $this->assertEqualsWithDelta($expectedTotal, (float) $response->json('data.total'), 0.05);
        $this->assertSame(2, $response->json('data.member_count'));
    }

    public function test_cancel_group_cancels_every_member_and_marks_group_cancelled(): void
    {
        [$hotelId, , $roomA, $roomB, $stayReasonId, $sourceId, $user] = $this->seedGroupBookingFixtures();
        $clientA = $this->makeClient();
        $clientB = $this->makeClient();

        $create = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/group-bookings', [
                'name' => 'Full Cancel Test Event',
                'members' => [
                    $this->memberDraft($roomA, $clientA, $stayReasonId, $sourceId, '2027-04-10', '2027-04-12'),
                    $this->memberDraft($roomB, $clientB, $stayReasonId, $sourceId, '2027-04-10', '2027-04-12'),
                ],
            ]);
        $groupId = $create->json('data.group.id');

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/group-bookings/{$groupId}/cancel", ['reason' => 'Event postponed']);

        $response->assertOk();
        $response->assertJsonPath('data.fully_cancelled', true);

        $this->assertSame(GroupBooking::STATUS_CANCELLED, GroupBooking::find($groupId)->status);
        foreach (Reservation::where('group_booking_id', $groupId)->get() as $reservation) {
            $this->assertTrue(Reservation::isCancelled((int) $reservation->reservation_status));
        }
    }

    public function test_partial_cancel_leaves_checked_in_member_active_and_group_active(): void
    {
        [$hotelId, , $roomA, $roomB, $stayReasonId, $sourceId, $user] = $this->seedGroupBookingFixtures();
        $clientA = $this->makeClient();
        $clientB = $this->makeClient();

        $create = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/group-bookings', [
                'name' => 'Partial Cancel Test Event',
                'members' => [
                    $this->memberDraft($roomA, $clientA, $stayReasonId, $sourceId, '2027-05-10', '2027-05-12'),
                    $this->memberDraft($roomB, $clientB, $stayReasonId, $sourceId, '2027-05-10', '2027-05-12'),
                ],
            ]);
        $groupId = $create->json('data.group.id');
        $memberIds = Reservation::where('group_booking_id', $groupId)->pluck('id')->all();

        // Simulate the first member already having checked in — cancel()
        // must refuse this one while the second member cancels normally.
        Reservation::where('id', $memberIds[0])->update(['logedin' => Reservation::LOGEDIN_IN_HOUSE]);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/group-bookings/{$groupId}/cancel", ['reason' => 'Partial']);

        $response->assertOk();
        $response->assertJsonPath('data.fully_cancelled', false);

        $this->assertSame(GroupBooking::STATUS_ACTIVE, GroupBooking::find($groupId)->status);
        $this->assertFalse(Reservation::isCancelled((int) Reservation::find($memberIds[0])->reservation_status));
        $this->assertTrue(Reservation::isCancelled((int) Reservation::find($memberIds[1])->reservation_status));
    }

    public function test_create_requires_permission(): void
    {
        [$hotelId, , $roomA, , $stayReasonId, $sourceId, $user] = $this->seedGroupBookingFixtures();
        $client = $this->makeClient();

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($hotelId);
        $user->syncRoles([]);
        $user->syncPermissions(['view reservations']);
        $registrar->forgetCachedPermissions();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/group-bookings', [
                'name' => 'Should Be Forbidden',
                'members' => [
                    $this->memberDraft($roomA, $client, $stayReasonId, $sourceId, '2027-06-10', '2027-06-12'),
                ],
            ]);

        $response->assertStatus(403);
    }
}
