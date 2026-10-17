<?php

namespace App\Http\Controllers\Grms;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Services\OccupancyBoardCache;
use App\Services\ReservationRoomStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Inbound webhook from GRMS — the reverse of GrmsClient's outbound calls.
 * Our own SyncRoomAvailableToGrmsJob only tells GRMS when OUR staff mark a
 * room ready from our board; GRMS also has its own housekeeping app, and
 * without this endpoint a room marked ready there would never be reflected
 * on our side — it would sit as "needs preparation" indefinitely even though
 * GRMS already considers it available for check-in.
 */
class GrmsWebhookController extends Controller
{
    public function roomReady(Request $request)
    {
        $secret = (string) config('grms.webhook_secret');
        if ($secret !== '' && $request->header('X-Grms-Webhook-Secret') !== $secret) {
            return response()->json(['message' => 'Invalid webhook secret'], Response::HTTP_UNAUTHORIZED);
        }

        $roomNumber = trim((string) $request->input('room_number'));
        if ($roomNumber === '') {
            return response()->json(['message' => 'Missing room_number'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $room = Room::where('number', $roomNumber)->where('active', 1)->first();

        if ($room === null) {
            Log::warning('GRMS room-ready webhook: no matching room', ['room_number' => $roomNumber]);

            // 200, not 404 — there's nothing GRMS can usefully retry for a
            // room number that doesn't exist on our side.
            return response()->json(['message' => 'Room not found, ignored'], Response::HTTP_OK);
        }

        $room->roomStatus = ReservationRoomStatusService::ROOM_AVAILABLE;
        $room->save();
        OccupancyBoardCache::bump();

        Log::info('GRMS room-ready webhook applied', ['room_id' => $room->id, 'room_number' => $roomNumber]);

        return response()->json(['message' => 'Accepted'], Response::HTTP_OK);
    }
}
