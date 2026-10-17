<?php

namespace App\Http\Controllers\Channex;

use App\Http\Controllers\Controller;
use App\Models\ChannexReservationRequest;
use App\Models\HotelAirbnbRequestSetting;
use App\Services\Channex\ChannexReservationRequestService;
use Illuminate\Http\Request;

/**
 * Staff-facing endpoints for Airbnb "Request to Book" items awaiting a
 * manual_review decision — the live in-app notification tells staff a
 * request is pending, this is what they act on it with.
 */
class ChannexReservationRequestController extends Controller
{
    public function __construct(private ChannexReservationRequestService $service) {}

    private function currentHotelId(Request $request): int
    {
        return (int) $request->attributes->get('currentHotelId');
    }

    public function index(Request $request)
    {
        $requests = ChannexReservationRequest::where('hotel_id', $this->currentHotelId($request))
            ->orderByDesc('received_at')
            ->when($request->boolean('pending_only', true), fn ($q) => $q->where('status', ChannexReservationRequest::STATUS_PENDING))
            ->get();

        return \SuccessData('Airbnb reservation requests retrieved', ['requests' => $requests]);
    }

    public function accept(Request $request, int $id)
    {
        $reservationRequest = ChannexReservationRequest::where('hotel_id', $this->currentHotelId($request))->findOrFail($id);

        try {
            $this->service->staffAccept($reservationRequest, (int) auth()->id());

            return \SuccessData('Reservation request accepted', $reservationRequest->fresh());
        } catch (\Throwable $e) {
            return \Failed($e->getMessage());
        }
    }

    public function decline(Request $request, int $id)
    {
        $request->validate([
            'reason' => 'nullable|string|in:'.implode(',', HotelAirbnbRequestSetting::DECLINE_REASONS),
        ]);

        $reservationRequest = ChannexReservationRequest::where('hotel_id', $this->currentHotelId($request))->findOrFail($id);

        try {
            $this->service->staffDecline(
                $reservationRequest,
                (int) auth()->id(),
                (string) ($request->input('reason') ?: HotelAirbnbRequestSetting::DECLINE_REASONS[3])
            );

            return \SuccessData('Reservation request declined', $reservationRequest->fresh());
        } catch (\Throwable $e) {
            return \Failed($e->getMessage());
        }
    }
}
