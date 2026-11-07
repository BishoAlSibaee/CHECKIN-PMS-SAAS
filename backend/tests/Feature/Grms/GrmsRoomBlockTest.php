<?php

namespace Tests\Feature\Grms;

use App\Models\GrmsOutboxEvent;
use App\Models\Room;
use App\Services\ReservationRoomStatusService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GRMS v1 contract: room.blocked / room.unblocked, fired only on the actual
 * transition into/out of out_of_service — plus the local mirror of GRMS's
 * own "cannot block an occupied room" rule.
 */
class GrmsRoomBlockTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    public function test_blocking_a_room_enqueues_room_blocked_with_the_reason(): void
    {
        $room = $this->findOrCreateAvailableRoom('2026-09-10', '2026-09-12');
        $hotelId = (int) $room->building->hotel_id;
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);

        $user = $this->userWithApiPermissions(['manage rooms']);
        $response = $this->actingAs($user, 'api')->postJson('/api/users/updateRoom', [
            'id' => $room->id,
            'roomStatus' => ReservationRoomStatusService::ROOM_OUT_OF_SERVICE,
            'reason' => 'AC repair',
        ]);
        $response->assertOk();

        $event = GrmsOutboxEvent::where('entity_type', 'room')->where('entity_id', (string) $room->id)->first();
        $this->assertNotNull($event);
        $this->assertSame('room.blocked', $event->type);
        $this->assertSame($room->number, $event->payload['data']['room']['code']);
        $this->assertSame('AC repair', $event->payload['data']['reason']);
    }

    public function test_unblocking_a_room_enqueues_room_unblocked(): void
    {
        $room = $this->findOrCreateAvailableRoom('2026-09-10', '2026-09-12');
        $hotelId = (int) $room->building->hotel_id;
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);
        Room::where('id', $room->id)->update(['roomStatus' => ReservationRoomStatusService::ROOM_OUT_OF_SERVICE]);

        $user = $this->userWithApiPermissions(['manage rooms']);
        $response = $this->actingAs($user, 'api')->postJson('/api/users/updateRoom', [
            'id' => $room->id,
            'roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE,
        ]);
        $response->assertOk();

        $event = GrmsOutboxEvent::where('entity_type', 'room')->where('entity_id', (string) $room->id)->first();
        $this->assertNotNull($event);
        $this->assertSame('room.unblocked', $event->type);
    }

    public function test_blocking_an_occupied_room_is_refused(): void
    {
        $room = $this->findOrCreateAvailableRoom('2026-09-10', '2026-09-12');
        Room::where('id', $room->id)->update(['roomStatus' => ReservationRoomStatusService::ROOM_OCCUPIED]);

        $user = $this->userWithApiPermissions(['manage rooms']);
        $response = $this->actingAs($user, 'api')->postJson('/api/users/updateRoom', [
            'id' => $room->id,
            'roomStatus' => ReservationRoomStatusService::ROOM_OUT_OF_SERVICE,
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            ReservationRoomStatusService::ROOM_OCCUPIED,
            (int) Room::find($room->id)->roomStatus,
        );
    }

    public function test_a_no_op_save_does_not_enqueue_anything(): void
    {
        $room = $this->findOrCreateAvailableRoom('2026-09-10', '2026-09-12');
        $hotelId = (int) $room->building->hotel_id;
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);

        $user = $this->userWithApiPermissions(['manage rooms']);
        $response = $this->actingAs($user, 'api')->postJson('/api/users/updateRoom', [
            'id' => $room->id,
            'capacity' => $room->capacity,
        ]);
        $response->assertOk();

        $this->assertSame(
            0,
            GrmsOutboxEvent::where('entity_type', 'room')->where('entity_id', (string) $room->id)->count(),
        );
    }
}
