<?php

namespace App\Services;

use App\Http\Controllers\ReservationController;
use App\Http\Requests\Reservation\MakeReservationRequest;
use App\Models\GroupBooking;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Suite;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates a group/event spanning multiple independent Reservation rows,
 * reusing ReservationController::makeReservation() per member exactly as
 * ChannexBookingIngestionService does — rather than duplicating its
 * pricing/hotel-boundary/availability logic.
 *
 * All-or-nothing: the whole batch is one DB transaction. A group's entire
 * value is "these reservations belong together" — a group with some members
 * silently missing because member 4's room went unavailable mid-submit would
 * be worse than a clean failure, since the frontend cart still holds every
 * draft and can simply resubmit after fixing the offending one.
 */
class GroupBookingCreationService
{
    public function __construct(
        private ReservationController $reservationController,
    ) {}

    /**
     * @param  array<string, mixed>  $groupData  name, company_name, contact_name, contact_mobile, contact_email, notes, group_discount_percent
     * @param  array<int, array<string, mixed>>  $memberDrafts  each shaped like MakeReservationRequest's own fields
     */
    public function createGroup(array $groupData, array $memberDrafts, int $hotelId, int $userId): GroupBooking
    {
        if ($memberDrafts === []) {
            throw new RuntimeException('A group booking needs at least one member reservation.');
        }

        return DB::transaction(function () use ($groupData, $memberDrafts, $hotelId, $userId) {
            $groupDiscountPercent = (float) ($groupData['group_discount_percent'] ?? 0);

            $groupBooking = GroupBooking::create([
                'hotel_id' => $hotelId,
                'name' => $groupData['name'],
                'company_name' => $groupData['company_name'] ?? null,
                'contact_name' => $groupData['contact_name'] ?? null,
                'contact_mobile' => $groupData['contact_mobile'] ?? null,
                'contact_email' => $groupData['contact_email'] ?? null,
                'notes' => $groupData['notes'] ?? null,
                'group_discount_percent' => $groupDiscountPercent,
                'status' => GroupBooking::STATUS_ACTIVE,
                'created_by' => $userId,
            ]);

            foreach ($memberDrafts as $draft) {
                $reservation = $this->createMemberReservation($draft, $groupDiscountPercent, $hotelId);
                $reservation->update(['group_booking_id' => $groupBooking->id]);
            }

            return $groupBooking->fresh(['reservations.client', 'reservations.reservationRooms.room']);
        });
    }

    /** @param  array<string, mixed>  $draft */
    private function createMemberReservation(array $draft, float $groupDiscountPercent, int $hotelId): Reservation
    {
        $manualDiscount = (float) ($draft['discount'] ?? 0) + $this->groupDiscountAmount($draft, $groupDiscountPercent);

        $payload = $draft;
        $payload['discount'] = round($manualDiscount, 2);
        // Not collected from the group-create form (it's meaningless for a
        // group member) — makeReservation() still writes this column, so a
        // stable default is required; the vestigial reservation_type field
        // has no real branching logic anywhere in the codebase either way.
        $payload['reservation_type'] = $draft['reservation_type'] ?? 0;

        $request = MakeReservationRequest::create('/api/users/makeReservation', 'POST', $payload);
        $request->setContainer(app());
        $request->attributes->set('currentHotelId', $hotelId);

        $response = $this->reservationController->makeReservation($request);
        $payloadOut = json_decode($response->getContent(), true);
        if (! ($payloadOut['success'] ?? false)) {
            throw new RuntimeException('Failed to create a member reservation: '.($payloadOut['message'] ?? 'unknown error'));
        }

        $reservationId = (int) ($payloadOut['data']['reservation']['id'] ?? 0);

        return Reservation::findOrFail($reservationId);
    }

    /**
     * Prices this member's rooms once, purely to turn a group discount
     * PERCENT into a currency amount fed into the ordinary `discount` field
     * makeReservation() already accepts — makeReservation() still does its
     * own authoritative pricing/persistence pass; this is a preview only.
     *
     * @param  array<string, mixed>  $draft
     */
    private function groupDiscountAmount(array $draft, float $groupDiscountPercent): float
    {
        if ($groupDiscountPercent <= 0) {
            return 0.0;
        }

        $pricingEngine = app(PricingEngine::class);
        $rentType = (int) ($draft['rent_type'] ?? 0);
        $priceMode = (int) ($draft['price_calculation_mode'] ?? 0);

        $totalBasePrice = 0.0;
        foreach ((array) ($draft['rooms'] ?? []) as $roomData) {
            $rooms = ! empty($roomData['suite_id'])
                ? Suite::findOrFail($roomData['suite_id'])->rooms()->where('active', 1)->get()
                : [Room::findOrFail($roomData['room_id'])];

            foreach ($rooms as $room) {
                $lines = $pricingEngine->buildDailyBreakdown(
                    $room,
                    $draft['start_date'],
                    $draft['expire_date'],
                    $rentType,
                    $priceMode
                );
                $totalBasePrice += $pricingEngine->sumBaseAmount($lines);
            }
        }

        return $totalBasePrice * ($groupDiscountPercent / 100);
    }
}
