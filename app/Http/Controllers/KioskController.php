<?php

namespace App\Http\Controllers;

use App\Http\Requests\Client\AddClientRequest;
use App\Http\Requests\Client\GetClientByRequest;
use App\Http\Requests\Kiosk\KioskConfirmPaymentRequest;
use App\Http\Requests\Kiosk\KioskReservationLookupRequest;
use App\Http\Requests\Reservation\BookingRoomAvailabilityRequest;
use App\Http\Requests\Reservation\MakeReservationRequest;
use App\Models\Reservation;
use App\Models\ReservationPay;
use App\Services\ReservationFinancialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Guest-facing self-service kiosk endpoints. Every method here is a thin
 * wrapper delegating to the existing staff-facing controllers/services —
 * no booking/payment business logic is duplicated.
 */
class KioskController extends Controller
{
    public function __construct(
        private ReservationController $reservationController,
        private ClientsController $clientsController,
        private ReservationFinancialService $reservationFinancialService,
    ) {
    }

    public function rooms(Request $request)
    {
        $device = $request->attributes->get('kioskDevice');
        $request->merge(['building_id' => $device->building_id]);

        return $this->reservationController->bookingRoomAvailability(
            app(BookingRoomAvailabilityRequest::class)
        );
    }

    public function lookupClient(Request $request)
    {
        if (!$request->filled('mobile') && !$request->filled('email') && !$request->filled('IdNumber')) {
            return \Failed('Provide at least one of mobile, email, or IdNumber.', 422);
        }

        return $this->clientsController->getBy(app(GetClientByRequest::class));
    }

    public function createClient(Request $request)
    {
        return $this->clientsController->store(app(AddClientRequest::class));
    }

    public function makeReservation(Request $request)
    {
        $device = $request->attributes->get('kioskDevice');

        if (!$request->filled('reservation_source_id') && $device->reservation_source_id) {
            $request->merge(['reservation_source_id' => $device->reservation_source_id]);
        }
        if (!$request->filled('stay_reason_id') && $device->default_stay_reason_id) {
            $request->merge(['stay_reason_id' => $device->default_stay_reason_id]);
        }
        // The kiosk never checks a guest straight into the room — payment/card issuance happens after booking.
        $request->merge(['logedin' => Reservation::LOGEDIN_NOT_IN_HOUSE]);

        return $this->reservationController->makeReservation(
            app(MakeReservationRequest::class)
        );
    }

    public function lookupReservation(KioskReservationLookupRequest $request)
    {
        $reservation = Reservation::with(['client', 'reservationRooms.room.roomType', 'payments'])
            ->where('confirmation_code', strtoupper($request->code))
            ->first();

        if (!$reservation || !$this->guestIdentityMatches($reservation, $request)) {
            return \Failed('Reservation not found', 404);
        }

        return \SuccessData('Reservation retrieved successfully', $reservation);
    }

    private function guestIdentityMatches(Reservation $reservation, KioskReservationLookupRequest $request): bool
    {
        $client = $reservation->client;

        if (!$client) {
            return false;
        }

        if ($request->filled('last_name')
            && strcasecmp(trim((string) $client->last_name), trim($request->last_name)) === 0) {
            return true;
        }

        if ($request->filled('mobile')
            && preg_replace('/\D+/', '', (string) $client->mobile) === preg_replace('/\D+/', '', $request->mobile)) {
            return true;
        }

        return false;
    }

    public function confirmPayment(KioskConfirmPaymentRequest $request, int $id)
    {
        // Idempotency guard: if this terminal reference was already confirmed
        // (e.g. the kiosk retried after losing the response to a network drop),
        // return the existing record instead of recording a duplicate payment.
        $existing = ReservationPay::where('terminal_reference', $request->terminal_reference)->first();
        if ($existing) {
            return \SuccessData('Payment already confirmed', [
                'reservation' => Reservation::with('payments')->findOrFail($id),
                'payment' => $existing,
            ]);
        }

        try {
            DB::beginTransaction();

            $reservation = Reservation::with('payments')->findOrFail($id);

            $payment = $this->reservationFinancialService->recordPayment(
                $reservation,
                (float) $request->pay,
                (int) auth()->id(),
                ReservationPay::TYPE_PAYMENT
            );

            $payment->update([
                'terminal_reference' => $request->terminal_reference,
                'payment_source' => 'kiosk',
                'card_last4' => $request->card_last4,
            ]);

            DB::commit();

            return \SuccessData('Payment confirmed', [
                'reservation' => $reservation->fresh(['payments']),
                'payment' => $payment,
            ]);
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();

            return \Failed($e->getMessage(), 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return \Failed($e->getMessage());
        }
    }
}
