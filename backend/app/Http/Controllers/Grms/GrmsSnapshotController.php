<?php

namespace App\Http\Controllers\Grms;

use App\Http\Controllers\Controller;
use App\Models\GrmsHotelCredential;
use App\Models\PosCategory;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Suite;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Services\Grms\GrmsSignatureService;

/**
 * GRMS v1 contract initial sync (GET /api/grms/v1/snapshot) — a cursor-paginated
 * dump of a hotel's rooms, in-house + upcoming reservations (default 60-day
 * window), and revenue codes, for GRMS to seed its own state when a hotel
 * first links. Authenticated the same way as the other v1 endpoints
 * (inbound HMAC credential) since GRMS is the caller.
 */
class GrmsSnapshotController extends Controller
{
    private const DEFAULT_WINDOW_DAYS = 60;
    private const PAGE_SIZE = 200;

    public function __construct(private GrmsSignatureService $signatureService) {}

    public function show(Request $request)
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

        $hotelId = (int) $credential->hotel_id;
        $cursor = (int) $request->input('cursor', 0);
        $windowDays = (int) $request->input('window_days', self::DEFAULT_WINDOW_DAYS);

        $roomsQuery = Room::whereHas('building', fn ($q) => $q->where('hotel_id', $hotelId))
            ->where('active', 1)
            ->where('id', '>', $cursor)
            ->with(['roomType', 'floor', 'building'])
            ->orderBy('id')
            ->limit(self::PAGE_SIZE);
        $rooms = $roomsQuery->get();

        // Contract section 5 "Suites" / 8b item 1 — a suite is not a room
        // code in v1, it's listed separately with its current member rooms.
        $suites = Suite::whereHas('building', fn ($q) => $q->where('hotel_id', $hotelId))
            ->where('active', 1)
            ->with(['rooms' => fn ($q) => $q->where('active', 1)])
            ->get();

        $reservations = Reservation::whereHas('reservationRooms.room.building', fn ($q) => $q->where('hotel_id', $hotelId))
            ->where('expire_date', '>=', now()->toDateString())
            ->where('start_date', '<=', now()->addDays($windowDays)->toDateString())
            ->whereNotIn('reservation_status', [Reservation::STATUS_CANCELLED])
            ->with('reservationRooms.room')
            ->get();

        $revenueCodes = PosCategory::where(fn ($q) => $q->whereNull('hotel_id')->orWhere('hotel_id', $hotelId))
            ->where('is_active', true)
            ->get(['id', 'name_en', 'name_ar']);

        $nextCursor = $rooms->last()?->id;

        return response()->json([
            'rooms' => $rooms->map(fn (Room $r) => [
                'code' => $r->number,
                'number' => $r->number,
                'room_type' => $r->roomType?->name_en,
                'floor' => $r->floor?->number,
            ]),
            'reservations' => $reservations->map(fn (Reservation $r) => [
                'id' => (string) $r->id,
                'confirmation_code' => $r->confirmation_code,
                'status' => \App\Services\Grms\GrmsContractEnums::reservationStatus((int) $r->reservation_status),
                'check_in_date' => $r->start_date,
                'check_out_date' => $r->expire_date,
                'rooms' => $r->reservationRooms->map(fn ($rr) => ['room' => ['code' => $rr->room?->number]])->values(),
            ]),
            'revenue_codes' => $revenueCodes->map(fn (PosCategory $c) => [
                'code' => (string) $c->id,
                'name' => $c->name_en,
            ]),
            'suites' => $suites->map(fn (Suite $s) => [
                'id' => (string) $s->id,
                'number' => (string) $s->number,
                'rooms' => $s->rooms->map(fn (Room $r) => $r->number)->values(),
            ]),
            'next_cursor' => $rooms->count() === self::PAGE_SIZE ? $nextCursor : null,
        ], Response::HTTP_OK);
    }
}
