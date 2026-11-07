<?php

namespace Tests\Feature\Grms;

use App\Models\Client;
use App\Models\GrmsOutboxEvent;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay_reason;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Confirms the pre-existing reservation lifecycle call sites (not just the
 * brand-new moveRoom()) actually reach the GRMS v1 outbox via
 * ReservationController::enqueueGrmsOutboxEvent(), gated on pms_protocol.
 */
class ReservationLifecycleOutboxTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function makeReservationWithRooms(array $rooms, string $start, string $end): array
    {
        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\EssentialReferenceDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $client = Client::first() ?? Client::create([
            'first_name' => 'Guest',
            'last_name' => 'Outbox'.$this->uniqueSuffix(),
            'international_code' => '+966',
            'mobile' => '05'.substr($this->uniqueSuffix(), -8),
            'IdType' => 'PASSPORT',
            'IdNumber' => 'TEST-'.$this->uniqueSuffix(),
            'gender' => 'MALE',
            'guest_type' => 'VISITOR',
        ]);
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();

        $reservation = Reservation::query()->create([
            'client_id' => $client->id,
            'start_date' => $start,
            'expire_date' => $end,
            'nights' => Carbon::parse($start)->diffInDays(Carbon::parse($end)),
            'reservation_type' => 0,
            'reservation_status' => Reservation::STATUS_CONFIRMED,
            'stay_reason_id' => $stayReason->id,
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'price_calculation_mode' => 0,
            'base_price' => 500,
            'classification_discount' => 0,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'subtotal' => 500,
            'taxes' => 75,
            'total' => 575,
            'logedin' => Reservation::LOGEDIN_NOT_IN_HOUSE,
            'login_time' => $start,
            'user_id' => $this->apiTestUser()->id,
        ]);

        $resRooms = [];
        foreach ($rooms as $room) {
            $resRooms[] = ReservationRoom::query()->create([
                'reservation_id' => $reservation->id,
                'room_id' => $room->id,
                'price' => 287.5,
            ]);
        }

        return [$reservation, $resRooms];
    }

    private function setHotelToV1(Room $room): int
    {
        $hotelId = (int) $room->building->hotel_id;
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);

        return $hotelId;
    }

    public function test_extending_a_reservation_enqueues_a_stay_extended_event(): void
    {
        Carbon::setTestNow('2026-08-16');

        $room = $this->findOrCreateAvailableRoom('2026-08-15', '2026-08-30');
        $hotelId = $this->setHotelToV1($room);
        [$reservation] = $this->makeReservationWithRooms([$room], '2026-08-15', '2026-08-18');

        $user = $this->userWithApiPermissions(['view reservations', 'update reservations']);
        $response = $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservation->id}/extend",
            ['expire_date' => '2026-08-20'],
        );
        $response->assertOk();

        $event = GrmsOutboxEvent::where('hotel_id', $hotelId)
            ->where('entity_id', (string) $reservation->id)
            ->where('type', 'stay.extended')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('2026-08-20', $event->payload['data']['rooms'][0]['check_out_date']);
        $this->assertSame($room->number, $event->payload['data']['rooms'][0]['room']['code']);

        Carbon::setTestNow();
    }

    public function test_cancelling_one_room_enqueues_a_partial_cancellation_with_only_that_room(): void
    {
        Carbon::setTestNow('2026-08-16');

        // findOrCreateAvailableRoom() picks the first room with no overlap —
        // calling it twice before either room has a reservation would return
        // the SAME room both times, so the second lookup excludes the first
        // (falling back to a fresh isolated room, same as the helper itself
        // does once it runs out of candidates).
        $roomA = $this->findOrCreateAvailableRoom('2026-08-16', '2026-08-18');
        $roomB = Room::where('active', 1)->where('id', '!=', $roomA->id)->whereHas('roomType')
            ->orderBy('id')->get()
            ->first(fn (Room $r) => !$this->roomHasReservationOverlap($r->id, '2026-08-16', '2026-08-18'))
            ?? $this->createIsolatedTestRoom();
        $hotelId = $this->setHotelToV1($roomA);
        [$reservation, $resRooms] = $this->makeReservationWithRooms([$roomA, $roomB], '2026-08-16', '2026-08-18');

        $user = $this->userWithApiPermissions(['view reservations', 'cancel reservations']);
        $response = $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservation->id}/rooms/{$resRooms[0]->id}/cancel",
            [],
        );
        $response->assertOk();

        $event = GrmsOutboxEvent::where('hotel_id', $hotelId)
            ->where('entity_id', (string) $reservation->id)
            ->where('type', 'reservation.cancelled')
            ->first();

        $this->assertNotNull($event);
        $this->assertCount(1, $event->payload['data']['rooms']);
        $this->assertSame($roomA->number, $event->payload['data']['rooms'][0]['room']['code']);

        Carbon::setTestNow();
    }

    public function test_a_legacy_hotel_never_gets_an_outbox_event(): void
    {
        Carbon::setTestNow('2026-08-16');

        $room = $this->findOrCreateAvailableRoom('2026-08-15', '2026-08-30');
        $hotelId = (int) $room->building->hotel_id;
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'legacy']);
        [$reservation] = $this->makeReservationWithRooms([$room], '2026-08-15', '2026-08-18');

        $user = $this->userWithApiPermissions(['view reservations', 'update reservations']);
        $response = $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservation->id}/extend",
            ['expire_date' => '2026-08-20'],
        );
        $response->assertOk();

        $this->assertSame(
            0,
            GrmsOutboxEvent::where('hotel_id', $hotelId)->where('entity_id', (string) $reservation->id)->count(),
        );

        Carbon::setTestNow();
    }
}
