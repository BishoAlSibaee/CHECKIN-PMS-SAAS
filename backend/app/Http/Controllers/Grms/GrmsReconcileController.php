<?php

namespace App\Http\Controllers\Grms;

use App\Http\Controllers\Controller;
use App\Models\GrmsHotelCredential;
use App\Models\PosCharge;
use App\Models\Reservation;
use App\Services\Grms\GrmsSignatureService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GRMS v1 contract daily reconciliation (GET /api/grms/v1/reconcile?date=) —
 * plain read, no comparison logic on our side; GRMS compares against its own
 * records and surfaces differences for review.
 */
class GrmsReconcileController extends Controller
{
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

        $date = (string) $request->input('date', now()->toDateString());
        $hotelId = (int) $credential->hotel_id;

        // Contract section 7: only stays covering this day (check_in_date <=
        // date < check_out_date) that are still live — a cancelled or
        // already-closed (checked-out) reservation must not appear, even if
        // its date range technically covers $date.
        $reservations = Reservation::whereHas('reservationRooms.room.building', fn ($q) => $q->where('hotel_id', $hotelId))
            ->where('start_date', '<=', $date)
            ->where('expire_date', '>', $date)
            ->where('reservation_status', '!=', Reservation::STATUS_CANCELLED)
            ->where(fn ($q) => $q->where('logedin', Reservation::LOGEDIN_IN_HOUSE)->orWhere('login_time', null))
            ->with('reservationRooms.room')
            ->get(['id', 'confirmation_code', 'total', 'reservation_status', 'start_date', 'expire_date', 'logedin', 'login_time']);

        $charges = PosCharge::whereNotNull('grms_charge_id')
            ->where('hotel_id', $hotelId)
            ->whereDate('charge_date', $date)
            ->get(['grms_charge_id', 'reservation_id', 'amount', 'tax_amount', 'status']);

        return response()->json([
            'date' => $date,
            'reservations' => $reservations->map(fn (Reservation $r) => [
                'id' => (string) $r->id,
                'confirmation_code' => $r->confirmation_code,
                'check_in_date' => $r->start_date,
                'check_out_date' => $r->expire_date,
                'rooms' => $r->reservationRooms
                    ->filter(fn ($rr) => !$rr->isCancelled($rr->status))
                    ->map(fn ($rr) => $rr->room?->number)
                    ->filter()
                    ->values(),
            ]),
            'charges' => $charges->map(fn (PosCharge $c) => [
                'charge_id' => $c->grms_charge_id,
                'reservation_id' => (string) $c->reservation_id,
                'total' => [
                    'amount' => (int) round(((float) $c->amount + (float) $c->tax_amount) * 100),
                    'currency' => 'SAR',
                ],
            ]),
        ], Response::HTTP_OK);
    }
}
