<?php

namespace App\Services;

use App\Http\Controllers\ReservationController;
use App\Http\Requests\Reservation\CancelReservationRequest;
use App\Models\GroupBooking;
use App\Models\Reservation;

/**
 * Cancels a group's member reservations one at a time, reusing
 * ReservationController::cancel() per member exactly as
 * ChannexBookingIngestionService::cancelExistingReservation() does.
 *
 * Deliberately the OPPOSITE of GroupBookingCreationService's all-or-nothing
 * transaction: by cancel time, member reservations are real, already-billable
 * stays. If one guest already checked in, blocking the other members'
 * cancellation because of it would actively harm the front desk's ability to
 * unwind a mostly-cancelled event. So: partial success is allowed, every
 * member's outcome is reported individually, and the group itself only
 * flips to STATUS_CANCELLED once every member is actually cancelled — staff
 * resolve any blocked member manually (e.g. check the guest out first) and
 * retry the group cancel, which then succeeds.
 */
class GroupBookingCancellationService
{
    public function __construct(
        private ReservationController $reservationController,
    ) {}

    /**
     * @return array{fully_cancelled: bool, members: array<int, array{reservation_id: int, success: bool, message: string}>}
     */
    public function cancelGroup(GroupBooking $groupBooking, ?string $reason): array
    {
        $members = $groupBooking->reservations()
            ->where('reservation_status', '!=', Reservation::STATUS_CANCELLED)
            ->get();

        $results = [];
        foreach ($members as $reservation) {
            $request = CancelReservationRequest::create(
                "/api/users/reservations/{$reservation->id}/cancel",
                'POST',
                ['reason' => $reason ?? 'Group booking cancelled']
            );
            $request->setContainer(app());

            $response = $this->reservationController->cancel($request, $reservation->id);
            $payload = json_decode($response->getContent(), true);
            $success = (bool) ($payload['success'] ?? false);

            $results[] = [
                'reservation_id' => $reservation->id,
                'success' => $success,
                'message' => $success ? 'Cancelled' : ($payload['message'] ?? 'Unknown error'),
            ];
        }

        $fullyCancelled = $groupBooking->reservations()
            ->where('reservation_status', '!=', Reservation::STATUS_CANCELLED)
            ->doesntExist();

        if ($fullyCancelled) {
            $groupBooking->update(['status' => GroupBooking::STATUS_CANCELLED]);
        }

        return ['fully_cancelled' => $fullyCancelled, 'members' => $results];
    }
}
