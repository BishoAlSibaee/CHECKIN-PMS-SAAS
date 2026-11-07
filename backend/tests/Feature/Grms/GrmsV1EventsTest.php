<?php

namespace Tests\Feature\Grms;

use App\Models\GrmsHotelCredential;
use App\Models\Room;
use App\Services\Grms\GrmsCredentialService;
use App\Services\Grms\GrmsSignatureService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GrmsV1EventsTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function inboundCredentialForHotel(int $hotelId): array
    {
        $result = app(GrmsCredentialService::class)->generate($hotelId, GrmsHotelCredential::DIRECTION_INBOUND);

        return [$result['credential']->key_id, $result['secret']];
    }

    private function signedHeaders(string $keyId, string $secret, string $body): array
    {
        $timestamp = (string) time();
        $signature = app(GrmsSignatureService::class)->sign($timestamp, $body, $secret);

        return [
            'X-Key-Id' => $keyId,
            'X-Timestamp' => $timestamp,
            'X-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    private function hotelId(): int
    {
        return (int) DB::table('hotels')->orderBy('id')->value('id');
    }

    public function test_events_endpoint_rejects_an_invalid_signature(): void
    {
        $hotelId = $this->hotelId();
        [$keyId] = $this->inboundCredentialForHotel($hotelId);

        $response = $this->postJson('/api/grms/v1/events', ['events' => []], [
            'X-Key-Id' => $keyId,
            'X-Timestamp' => (string) time(),
            'X-Signature' => 'wrong',
        ]);

        $response->assertStatus(401);
    }

    public function test_events_endpoint_rejects_a_legacy_hotel(): void
    {
        $hotelId = $this->hotelId();
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'legacy']);
        [$keyId, $secret] = $this->inboundCredentialForHotel($hotelId);
        $body = json_encode(['events' => []]);

        $response = $this->call(
            'POST',
            '/api/grms/v1/events',
            [], [], [],
            $this->transformHeadersToServerVars($this->signedHeaders($keyId, $secret, $body)),
            $body,
        );

        $response->assertStatus(409);
    }

    public function test_housekeeping_status_changed_updates_room_status(): void
    {
        $hotelId = $this->hotelId();
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);
        [$keyId, $secret] = $this->inboundCredentialForHotel($hotelId);

        $room = Room::whereHas('building', fn ($q) => $q->where('hotel_id', $hotelId))->first();
        $this->assertNotNull($room);

        $body = json_encode(['events' => [[
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'room.housekeeping_status_changed',
            'data' => ['room' => ['code' => $room->number], 'status' => 'out_of_order'],
        ]]]);

        $response = $this->call(
            'POST',
            '/api/grms/v1/events',
            [], [], [],
            $this->transformHeadersToServerVars($this->signedHeaders($keyId, $secret, $body)),
            $body,
        );

        $response->assertStatus(202);
        $response->assertJsonPath('results.0.status', 'accepted');
        $this->assertSame(
            \App\Services\ReservationRoomStatusService::ROOM_OUT_OF_SERVICE,
            (int) Room::find($room->id)->roomStatus,
        );
    }

    public function test_a_repeated_event_id_is_reported_as_duplicate(): void
    {
        $hotelId = $this->hotelId();
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);
        [$keyId, $secret] = $this->inboundCredentialForHotel($hotelId);
        $room = Room::whereHas('building', fn ($q) => $q->where('hotel_id', $hotelId))->first();

        $eventId = (string) \Illuminate\Support\Str::uuid();
        $event = [
            'id' => $eventId,
            'type' => 'room.housekeeping_status_changed',
            'data' => ['room' => ['code' => $room->number], 'status' => 'clean'],
        ];

        foreach ([1, 2] as $attempt) {
            $body = json_encode(['events' => [$event]]);
            $response = $this->call(
                'POST',
                '/api/grms/v1/events',
                [], [], [],
                $this->transformHeadersToServerVars($this->signedHeaders($keyId, $secret, $body)),
                $body,
            );
            $response->assertStatus(202);
            $response->assertJsonPath('results.0.status', $attempt === 1 ? 'accepted' : 'duplicate');
        }
    }

    public function test_reconcile_requires_a_valid_signature(): void
    {
        $hotelId = $this->hotelId();
        [$keyId] = $this->inboundCredentialForHotel($hotelId);

        $response = $this->getJson('/api/grms/v1/reconcile?date=2026-08-15', [
            'X-Key-Id' => $keyId,
            'X-Timestamp' => (string) time(),
            'X-Signature' => 'wrong',
        ]);

        $response->assertStatus(401);
    }

    public function test_snapshot_returns_rooms_for_the_credentials_hotel(): void
    {
        $hotelId = $this->hotelId();
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);
        [$keyId, $secret] = $this->inboundCredentialForHotel($hotelId);

        $timestamp = (string) time();
        $signature = app(GrmsSignatureService::class)->sign($timestamp, '', $secret);

        $response = $this->call(
            'GET',
            '/api/grms/v1/snapshot',
            [], [], [],
            $this->transformHeadersToServerVars([
                'X-Key-Id' => $keyId,
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
            ]),
        );

        $response->assertStatus(200);
        $this->assertGreaterThan(0, count($response->json('rooms')));
    }
}
