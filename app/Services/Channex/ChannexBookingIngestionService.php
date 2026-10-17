<?php

namespace App\Services\Channex;

use App\Http\Controllers\ReservationController;
use App\Http\Requests\Reservation\CancelReservationRequest;
use App\Http\Requests\Reservation\MakeReservationRequest;
use App\Models\ChannexProperty;
use App\Models\ChannexRatePlanMapping;
use App\Models\ChannexRoomTypeMapping;
use App\Models\Client;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Reservation_source;
use App\Models\Room;
use App\Models\Stay_reason;
use App\Models\User;
use App\Services\Accounting\AccountingPostingService;
use App\Services\GuestClassificationDiscountService;
use App\Services\NotificationService;
use App\Services\PricingEngine;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Converts one Channex booking payload (see GET /booking_revisions/:id or
 * GET /bookings — same shape) into a real reservation, reusing
 * ReservationController::makeReservation() rather than duplicating its
 * pricing/hotel-boundary/availability logic. Deliberately bypasses
 * MakeReservationRequest's automatic FormRequest validation (which only
 * fires when Laravel's router resolves the controller via the container,
 * not on a direct method call) — every field passed in is already resolved
 * from real data (a room we just confirmed is free, a client we just
 * found/created), so re-validating it here would be redundant, not safer.
 */
class ChannexBookingIngestionService
{
    public function __construct(
        private ReservationController $reservationController,
        private AccountingPostingService $accountingPostingService,
        private OtaAllocationService $otaAllocationService,
        private \App\Services\ReservationRoomStatusService $roomStatusService,
        private PricingEngine $pricingEngine,
        private RevenueAccrualService $revenueAccrualService,
        private GuestClassificationDiscountService $guestClassificationDiscountService,
        private NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $booking  the "attributes" object from a Channex booking/booking_revision payload
     * @param  string|null  $eventHint  the webhook's event name (booking_new / booking_modification /
     *                                  booking_cancellation), when this call came from the webhook rather
     *                                  than the backup poller (which has no such hint — see isCancellation()).
     * @return Reservation|null  null only for a cancellation of a booking_id we never actually ingested
     *                           (e.g. the guest cancelled within the same minute, before our webhook/poll
     *                           got to the original) — nothing exists to release.
     */
    public function ingest(array $booking, ?string $eventHint = null): ?Reservation
    {
        $property = ChannexProperty::where('channex_property_id', $booking['property_id'])->first();
        if ($property === null) {
            throw new RuntimeException("Unknown Channex property_id: {$booking['property_id']}");
        }
        $hotelId = (int) $property->hotel_id;

        // Authenticate as the integration's service user up front — needed
        // both by the cancel path below (change-log snapshot reads
        // auth()->user()) and by makeReservation() further down.
        $serviceUser = User::where('job_number', 'CHANNEX-SVC')->firstOrFail();
        Auth::guard('api')->setUser($serviceUser);

        $source = $this->findOrCreateSource($booking);
        $externalBookingId = (string) $booking['booking_id'];
        $existing = Reservation::where('reservation_source_id', $source->id)
            ->where('external_booking_id', $externalBookingId)
            ->first();
        $isCancellation = $this->isCancellation($booking, $eventHint);
        // Captured before the branches below touch $existing — a
        // modification also falls through to the same rebooking code past
        // this point, so this is the only place that can still tell a
        // genuinely first-time booking apart from a rebook-after-change.
        $isGenuinelyNew = $existing === null && ! $isCancellation;

        if ($existing !== null) {
            if ($isCancellation) {
                $this->cancelExistingReservation($existing);

                return $existing->fresh();
            }

            // A modification (date/room/rate-plan change) to a booking we
            // already ingested. Channex sends the FULL new booking state on
            // every revision, not a diff, so the simplest correct handling
            // is the same the OTA itself effectively did: release what was
            // there and rebook fresh with whatever this revision now says —
            // rather than trying to patch dates/rooms/pricing in place.
            $this->cancelExistingReservation($existing);
        } elseif ($isCancellation) {
            // Nothing on file for this booking_id to release. Not an error —
            // just log it so a genuinely missed original booking is visible,
            // without endlessly retrying a cancellation that can never
            // "succeed" in the sense of finding something to cancel.
            \Illuminate\Support\Facades\Log::warning('Channex cancellation for unknown booking', [
                'booking_id' => $externalBookingId,
                'reservation_source_id' => $source->id,
            ]);

            return null;
        }

        $client = $this->findOrCreateClient((array) ($booking['customer'] ?? []), $hotelId);

        $roomsPayload = [];
        $roomTypeNames = [];
        foreach ((array) ($booking['rooms'] ?? []) as $room) {
            $roomTypeMapping = ChannexRoomTypeMapping::where('hotel_id', $hotelId)
                ->where('channex_room_type_id', $room['room_type_id'])
                ->with('roomType')
                ->first();
            if ($roomTypeMapping === null) {
                throw new RuntimeException("Unmapped Channex room_type_id: {$room['room_type_id']}");
            }
            if ($roomTypeMapping->roomType !== null) {
                $roomTypeNames[] = $roomTypeMapping->roomType->name_en;
            }

            $roomId = $this->assignAvailableRoom(
                $hotelId,
                (int) $roomTypeMapping->room_type_id,
                (string) $room['checkin_date'],
                (string) $room['checkout_date']
            );
            if ($roomId === null) {
                // A genuine oversell: the guest already booked/paid on the OTA —
                // never silently drop it. This exception surfaces as a "failed"
                // booking_revisions row with this message for staff to resolve
                // manually (relocate/upgrade another guest) before arrival.
                throw new RuntimeException(
                    "No room of type #{$roomTypeMapping->room_type_id} available for {$room['checkin_date']}–{$room['checkout_date']} — manual overbooking resolution needed."
                );
            }

            $roomsPayload[] = ['room_id' => $roomId];
        }

        if ($roomsPayload === []) {
            throw new RuntimeException('Channex booking has no rooms.');
        }

        $paymentCollect = (string) ($booking['payment_collect'] ?? 'property');
        $otaPaymentType = $paymentCollect === 'property' ? 'pay_at_hotel' : 'prepaid_platform';
        $commissionAmount = $booking['ota_commission'] !== null ? (float) $booking['ota_commission'] : null;

        $request = MakeReservationRequest::create('/api/users/makeReservation', 'POST', [
            'client_id' => $client->id,
            'rooms' => $roomsPayload,
            'start_date' => $booking['arrival_date'],
            'expire_date' => $booking['departure_date'],
            'reservation_type' => 0,
            'stay_reason_id' => Stay_reason::query()->value('id'),
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'price_calculation_mode' => 0,
            // A platform booking is a real guest commitment the moment it
            // arrives — whether prepaid or pay-at-hotel — not a tentative
            // walk-in inquiry. Without this, makeReservation() defaults
            // unpaid bookings to STATUS_PENDING_PAYMENT, which every
            // room-conflict/allocation check in the app treats as
            // non-blocking (Reservation::nonBlockingInventoryStatuses()),
            // silently leaving the room open to be double-booked.
            'reservation_status' => Reservation::STATUS_CONFIRMED,
        ]);
        $request->setContainer(app());
        $request->attributes->set('currentHotelId', $hotelId);

        $response = $this->reservationController->makeReservation($request);
        $payload = json_decode($response->getContent(), true);
        if (! ($payload['success'] ?? false)) {
            throw new RuntimeException('makeReservation failed: '.($payload['message'] ?? 'unknown error'));
        }

        $reservationId = (int) ($payload['data']['reservation']['id'] ?? 0);
        $reservation = Reservation::findOrFail($reservationId);
        $reservation->update([
            'ota_payment_type' => $otaPaymentType,
            'external_booking_id' => (string) $booking['booking_id'],
            'external_commission_amount' => $commissionAmount,
        ]);

        // makeReservation() just priced this using the hotel's own default
        // calculation, independent of whichever OTA rate plan the guest
        // actually booked. The platform was quoted (and the guest paid based
        // on) ChannexAriPushService's rate-plan-adjusted price, so the folio
        // must be re-priced with that exact same formula — not left to drift
        // from whatever the plain room rate happens to compute today.
        $this->applyRatePlanPricing($reservation, (array) ($booking['rooms'] ?? []));

        if ($isGenuinelyNew) {
            $this->notifications->notify(
                $hotelId,
                'new_booking',
                'NOTIFICATIONS.NEW_BOOKING_TITLE',
                [
                    'channel' => $source->name_en,
                    'room' => implode(', ', array_unique($roomTypeNames)) ?: '—',
                ],
                '/booking/reservations/'.$reservation->id,
                'view reservations'
            );
        }

        if ($otaPaymentType === 'prepaid_platform') {
            // The accounting entry still uses the platform's own reported
            // collection amount (not our folio total) — that figure tracks
            // what money the platform actually owes us, which is a separate
            // question from what the guest's folio should read.
            $collectedAmount = isset($booking['amount']) ? (float) $booking['amount'] : null;
            $this->accountingPostingService->postOtaPrepaidRecognition($reservation->fresh(), $collectedAmount);
        }

        return $reservation->fresh();
    }

    /**
     * Channex's webhook event name is the reliable signal
     * (booking_cancellation) when this call came from the webhook; the
     * backup poller's feed carries no event name at all, so it falls back to
     * whatever status field the booking detail itself reports — Channex's
     * booking objects use a "status" attribute with values including
     * "cancelled"/"canceled" for this.
     *
     * @param  array<string, mixed>  $booking
     */
    private function isCancellation(array $booking, ?string $eventHint): bool
    {
        if ($eventHint !== null && str_contains($eventHint, 'cancel')) {
            return true;
        }

        $status = strtolower((string) ($booking['status'] ?? ''));

        return in_array($status, ['cancelled', 'canceled'], true);
    }

    /**
     * Channex's docs confirm the Booking/booking_revision "status" attribute
     * (new/modified/cancelled) is present on the booking_revisions feed item
     * itself, the full booking_revision detail, AND the plain Booking object
     * — the exact same field, everywhere, with the exact same three values.
     * The polling and backfill commands (which have no webhook event name to
     * go on) use this to translate that field into the same event-hint
     * string the webhook path passes to ingest(), so a poll-discovered
     * revision's cancellation detection is exactly as reliable as a
     * webhook-delivered one — not a guess, and not solely dependent on
     * ingest()'s own secondary status-field fallback re-derived later from a
     * second API call.
     */
    public static function mapBookingStatusToEvent(?string $status): ?string
    {
        return match (strtolower((string) $status)) {
            'new' => 'booking_new',
            'modified' => 'booking_modification',
            'cancelled', 'canceled' => 'booking_cancellation',
            default => null,
        };
    }

    /**
     * Releases the room and reverses the OTA prepaid accounting entry (if
     * any) for a reservation Channex says is no longer valid — either
     * because the guest cancelled it outright, or because it's being
     * replaced by a rebooked revision (see ingest()'s modification branch).
     */
    private function cancelExistingReservation(Reservation $reservation): void
    {
        if (! Reservation::isCancelled((int) $reservation->reservation_status)) {
            $today = Carbon::today()->toDateString();
            if ($reservation->expire_date < $today || (int) $reservation->logedin === 1) {
                // The stay is already over, or the guest is physically
                // checked in — auto-cancelling would be wrong (and
                // ReservationController::cancel() would refuse it anyway).
                // This needs a human, not a silent skip.
                throw new RuntimeException(
                    "Channex reported reservation #{$reservation->id} (external booking {$reservation->external_booking_id}) as cancelled/modified, ".
                    'but it has already checked in or its stay has passed — manual resolution needed.'
                );
            }

            $request = CancelReservationRequest::create(
                "/api/users/reservations/{$reservation->id}/cancel",
                'POST',
                ['reason' => 'Cancelled or superseded via Channex']
            );
            $request->setContainer(app());

            $response = $this->reservationController->cancel($request, $reservation->id);
            $payload = json_decode($response->getContent(), true);
            if (! ($payload['success'] ?? false)) {
                throw new RuntimeException('Cancelling the superseded reservation failed: '.($payload['message'] ?? 'unknown error'));
            }
        }

        // Free the [reservation_source_id, external_booking_id] slot so a
        // modification's rebooking (or any later booking) can reuse this
        // booking_id — the cancelled row keeps a traceable, no-longer-unique
        // marker instead of silently losing which Channex booking it was.
        if ($reservation->external_booking_id !== null && ! str_contains($reservation->external_booking_id, '-superseded-')) {
            $reservation->update([
                'external_booking_id' => $reservation->external_booking_id.'-superseded-'.now()->format('YmdHis'),
            ]);
        }

        $this->accountingPostingService->reverseOtaPrepaidRecognition($reservation->fresh());
    }

    /** @param  array<string, mixed>  $customer */
    private function findOrCreateClient(array $customer, int $hotelId): Client
    {
        $tenantId = (int) Hotel::findOrFail($hotelId)->tenant_id;

        $email = $customer['mail'] ?? null;
        $mobile = preg_replace('/\D/', '', (string) ($customer['phone'] ?? '')) ?: null;

        if ($email) {
            $existing = Client::where('tenant_id', $tenantId)->where('email', $email)->first();
            if ($existing) {
                return $existing;
            }
        }
        if ($mobile) {
            $existing = Client::where('tenant_id', $tenantId)->where('mobile', $mobile)->first();
            if ($existing) {
                return $existing;
            }
        }

        // OTAs never supply real ID/passport data or gender — these are
        // placeholders the front desk corrects when the guest actually
        // checks in and presents ID, exactly as for any other booking.
        return Client::create([
            'tenant_id' => $tenantId,
            'first_name' => (string) ($customer['name'] ?? 'Guest'),
            'last_name' => (string) ($customer['surname'] ?? '-'),
            'email' => $email,
            'international_code' => '',
            'mobile' => $mobile ?: ('OTA'.Str::random(9)),
            'IdType' => 'PASSPORT',
            'IdNumber' => 'OTA-'.Str::random(12),
            'gender' => 'MALE',
            'guest_type' => 'VISITOR',
            'nationality' => $customer['country'] ?? null,
        ]);
    }

    /** @param  array<string, mixed>  $booking */
    /**
     * Re-price the reservation's rooms using each room's actual OTA rate
     * plan (base rate × price_adjustment_percent — the exact same formula
     * ChannexAriPushService used to quote this price to the platform), so
     * the folio matches what the guest saw and paid for. Rooms whose
     * rate_plan_id isn't a mapped OtaRatePlan are left at whatever
     * makeReservation() already computed.
     *
     * @param  array<int, array<string, mixed>>  $bookingRooms
     */
    private function applyRatePlanPricing(Reservation $reservation, array $bookingRooms): void
    {
        $resRooms = $reservation->reservationRooms()->with('room')->orderBy('id')->get();
        $totalBasePrice = 0.0;
        $anyRatePlanApplied = false;

        foreach (array_values($bookingRooms) as $index => $bookingRoom) {
            $resRoom = $resRooms->get($index);
            if ($resRoom === null || $resRoom->room === null) {
                continue;
            }

            $ratePlanId = $bookingRoom['rate_plan_id'] ?? null;
            $otaRatePlan = $ratePlanId !== null
                ? ChannexRatePlanMapping::where('channex_rate_plan_id', $ratePlanId)
                    ->with('otaRatePlan')
                    ->first()
                    ?->otaRatePlan
                : null;

            $lines = $this->pricingEngine->buildDailyBreakdown(
                $resRoom->room,
                (string) $bookingRoom['checkin_date'],
                (string) $bookingRoom['checkout_date'],
                0,
                0
            );

            if ($otaRatePlan !== null && $otaRatePlan->is_active) {
                $anyRatePlanApplied = true;
                $factor = 1 + ((float) $otaRatePlan->price_adjustment_percent / 100);
                foreach ($lines as &$line) {
                    $line['base_amount'] = round((float) $line['base_amount'] * $factor, 2);
                }
                unset($line);
            }

            $roomPrice = $this->pricingEngine->sumBaseAmount($lines);
            $totalBasePrice += $roomPrice;

            $this->revenueAccrualService->persistDailyCharges(
                $reservation->id,
                $resRoom->id,
                $resRoom->room_id,
                0,
                $lines
            );
            $resRoom->update(['price' => round($roomPrice * (1 + \App\Services\RefundPolicyService::TAX_RATE), 2)]);
        }

        if (! $anyRatePlanApplied) {
            return;
        }

        $totals = $this->guestClassificationDiscountService->calculateTotals(
            $totalBasePrice,
            (float) $reservation->classification_discount,
            (float) $reservation->discount,
            (float) $reservation->extras,
            (float) $reservation->penalties
        );

        $reservation->update([
            'base_price' => round($totalBasePrice, 2),
            'subtotal' => $totals['subtotal'],
            'taxes' => $totals['taxes'],
            'total' => $totals['total'],
        ]);
    }

    private function findOrCreateSource(array $booking): Reservation_source
    {
        $channelCode = $booking['channel_id'] ?? null;
        $otaName = (string) ($booking['ota_name'] ?? 'OTA');

        if ($channelCode) {
            $existing = Reservation_source::where('channex_channel_code', $channelCode)->first();
            if ($existing) {
                return $existing;
            }
        }

        return Reservation_source::firstOrCreate(
            ['name_en' => $otaName],
            [
                'name_ar' => $otaName,
                'description' => 'Auto-created from Channex',
                'is_ota_channel' => true,
                'channex_channel_code' => $channelCode,
            ]
        );
    }

    private function assignAvailableRoom(int $hotelId, int $roomTypeId, string $checkin, string $checkout): ?int
    {
        // The physical room-conflict check below is not enough on its own —
        // staff manually cap how many rooms of this type are released to OTA
        // channels (RoomTypeOtaAllocation), and that ceiling must never be
        // exceeded even when physically free rooms remain. This is exactly
        // what Phase 4's ARI push tells Channex is sellable, so ingestion must
        // enforce the same ceiling on the way in.
        $availability = $this->otaAllocationService->availabilityForDateRange(
            $hotelId,
            $roomTypeId,
            Carbon::parse($checkin),
            Carbon::parse($checkout)
        );
        if ($availability === [] || min($availability) <= 0) {
            throw new RuntimeException(
                "Room type #{$roomTypeId} has no OTA allocation headroom for {$checkin}–{$checkout} — ".
                'increase the allocation for this room type before this booking can be accepted.'
            );
        }

        $candidateRoomIds = Room::query()
            ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
            ->where('buildings.hotel_id', $hotelId)
            ->where('rooms.room_type_id', $roomTypeId)
            ->where('rooms.active', 1)
            ->pluck('rooms.id');

        if ($candidateRoomIds->isEmpty()) {
            return null;
        }

        $bookedRoomIds = ReservationRoom::query()
            ->whereIn('room_id', $candidateRoomIds)
            ->where('status', ReservationRoom::STATUS_ACTIVE)
            ->whereHas('reservation', fn ($q) => $q
                ->whereNotIn('reservation_status', Reservation::nonBlockingInventoryStatuses())
                ->where('start_date', '<', $checkout)
                ->where('expire_date', '>', $checkin))
            ->pluck('room_id');

        foreach ($candidateRoomIds->diff($bookedRoomIds) as $roomId) {
            // A room with a guest still checked in blocks ANY future booking
            // (not just overlapping dates) until checkout is registered —
            // same date-blind rule ReservationController::makeReservation()
            // enforces itself; skip it here rather than letting that real
            // reservation attempt fail downstream.
            if ($this->roomStatusService->findActiveInHouseReservationId((int) $roomId) === null) {
                return (int) $roomId;
            }
        }

        return null;
    }
}
