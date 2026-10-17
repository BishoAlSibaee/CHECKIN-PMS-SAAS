<?php

namespace Tests\Feature\Grms;

use App\Services\ReservationRoomStatusService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;
use Tests\Support\InteractsWithReservationFixtures;

class GrmsWebhookTest extends TestCase
{
    use InteractsWithReservationFixtures;

    public function test_wrong_secret_is_rejected(): void
    {
        Config::set('grms.webhook_secret', 'correct-secret');

        $response = $this->postJson('/api/grms/room-ready', ['room_number' => '101'], [
            'X-Grms-Webhook-Secret' => 'wrong-secret',
        ]);

        $response->assertStatus(401);
    }

    public function test_correct_secret_marks_room_available(): void
    {
        Config::set('grms.webhook_secret', 'correct-secret');

        $room = $this->createIsolatedTestRoom();
        $this->assertNotNull($room);
        $room->roomStatus = 3; // needs preparation
        $room->save();

        $response = $this->postJson('/api/grms/room-ready', ['room_number' => $room->number], [
            'X-Grms-Webhook-Secret' => 'correct-secret',
        ]);

        $response->assertOk();
        $this->assertSame(
            ReservationRoomStatusService::ROOM_AVAILABLE,
            $room->fresh()->roomStatus
        );
    }

    public function test_unknown_room_number_is_accepted_but_ignored(): void
    {
        Config::set('grms.webhook_secret', '');

        $response = $this->postJson('/api/grms/room-ready', ['room_number' => 'NO-SUCH-ROOM-999']);

        $response->assertOk();
    }

    public function test_missing_room_number_is_rejected(): void
    {
        Config::set('grms.webhook_secret', '');

        $response = $this->postJson('/api/grms/room-ready', []);

        $response->assertStatus(422);
    }
}
