<?php

namespace App\Http\Controllers\Grms;

use App\Http\Controllers\Controller;
use App\Models\GrmsHotelCredential;
use App\Models\GrmsInboundEvent;
use App\Models\Room;
use App\Services\Grms\GrmsSignatureService;
use App\Services\HotelLivePublisher;
use App\Services\OccupancyBoardCache;
use App\Services\ReservationRoomStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GRMS v1 contract inbound events (POST /api/grms/v1/events) — room-level
 * housekeeping/occupancy/maintenance updates from GRMS. The reverse of
 * DispatchGrmsOutboxCommand. Coexists with the legacy room-ready webhook
 * (GrmsWebhookController) during the pms_protocol migration — see hotels.pms_protocol.
 *
 * Only room.housekeeping_status_changed actually changes anything on our
 * side today (no occupancy-sensor or maintenance-ticket model exists yet —
 * maintenance.requested is surfaced as a live staff notification only).
 */
class GrmsEventsController extends Controller
{
    /** Maps the contract's 4 housekeeping states to our 4 room statuses. */
    private const HOUSEKEEPING_STATUS_MAP = [
        'dirty' => ReservationRoomStatusService::ROOM_PREPARATION,
        'clean' => ReservationRoomStatusService::ROOM_AVAILABLE,
        'inspected' => ReservationRoomStatusService::ROOM_AVAILABLE,
        'out_of_order' => ReservationRoomStatusService::ROOM_OUT_OF_SERVICE,
    ];

    public function __construct(private GrmsSignatureService $signatureService) {}

    public function receive(Request $request)
    {
        $keyId = $request->header('X-Key-Id');
        $credential = $keyId ? GrmsHotelCredential::where('key_id', $keyId)
            ->where('direction', GrmsHotelCredential::DIRECTION_INBOUND)
            ->where('is_active', true)
            ->first() : null;

        if (!$credential) {
            return response()->json(['message' => 'Unknown or inactive key_id'], Response::HTTP_UNAUTHORIZED);
        }

        $verification = $this->signatureService->verify($request, $credential->secret);
        if (!$verification['ok']) {
            return response()->json(['message' => $verification['reason']], Response::HTTP_UNAUTHORIZED);
        }

        if (!\App\Services\Grms\GrmsProtocolGuard::allows((int) $credential->hotel_id, 'v1')) {
            return response()->json(['message' => 'other_protocol'], Response::HTTP_CONFLICT);
        }

        $events = $request->input('events', []);
        $results = [];

        foreach ($events as $event) {
            $results[] = $this->processOne($credential->hotel_id, $event);
        }

        return response()->json(['results' => $results], Response::HTTP_ACCEPTED);
    }

    /** @param array<string, mixed> $event */
    private function processOne(int $hotelId, array $event): array
    {
        $id = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');

        if ($id === '') {
            return ['id' => $id, 'status' => 'rejected', 'reason' => 'missing_id'];
        }

        if (GrmsInboundEvent::where('event_uuid', $id)->exists()) {
            return ['id' => $id, 'status' => 'duplicate'];
        }

        GrmsInboundEvent::create(['event_uuid' => $id, 'type' => $type, 'processed_at' => now()]);

        return match ($type) {
            'room.housekeeping_status_changed' => $this->applyHousekeepingStatus($hotelId, $event),
            'maintenance.requested' => $this->applyMaintenanceRequested($hotelId, $event),
            'room.occupancy_changed' => ['id' => $id, 'status' => 'accepted'], // not consumed, see class docblock
            default => ['id' => $id, 'status' => 'rejected', 'reason' => 'unknown_type'],
        };
    }

    private function applyHousekeepingStatus(int $hotelId, array $event): array
    {
        $id = (string) $event['id'];
        $roomCode = (string) ($event['data']['room']['code'] ?? '');
        $status = (string) ($event['data']['status'] ?? '');

        $targetStatus = self::HOUSEKEEPING_STATUS_MAP[$status] ?? null;
        if ($targetStatus === null) {
            return ['id' => $id, 'status' => 'rejected', 'reason' => 'unknown_status'];
        }

        $room = Room::whereHas('building', fn ($q) => $q->where('hotel_id', $hotelId))
            ->where('number', $roomCode)
            ->where('active', 1)
            ->first();

        if (!$room) {
            // Same reasoning as the legacy room-ready webhook: nothing GRMS
            // can usefully retry for a room code that doesn't exist on our side.
            return ['id' => $id, 'status' => 'accepted'];
        }

        $room->roomStatus = $targetStatus;
        $room->save();

        OccupancyBoardCache::bump();

        return ['id' => $id, 'status' => 'accepted'];
    }

    private function applyMaintenanceRequested(int $hotelId, array $event): array
    {
        $roomCode = (string) ($event['data']['room']['code'] ?? '');
        $room = Room::whereHas('building', fn ($q) => $q->where('hotel_id', $hotelId))
            ->where('number', $roomCode)
            ->first();

        app(HotelLivePublisher::class)->publish(
            [HotelLivePublisher::SCOPE_PROPERTY, HotelLivePublisher::SCOPE_DASHBOARD],
            'maintenance_requested',
            $room ? ['type' => 'room', 'id' => $room->id] : null,
        );

        return ['id' => (string) $event['id'], 'status' => 'accepted'];
    }
}
