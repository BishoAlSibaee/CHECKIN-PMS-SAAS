<?php

namespace App\Jobs;

use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Services\GrmsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pushes reservation lifecycle changes to the Guest Room Management System (in-room
 * AC/lighting/door-lock controller): arms rooms on creation via setReservationApi,
 * releases them via checkoutApi on check-out AND on cancellation (GRMS has no
 * separate "cancel" endpoint — checkout is the only room-release call it exposes).
 * Fire-and-forget from the caller's perspective — a GRMS outage must never block or
 * fail a reservation (see notifyReservationLive() in ReservationController, whose
 * try/catch this mirrors).
 *
 * Other lifecycle actions (plain edits, partial/date-shorten refunds) are
 * intentionally no-ops here until GRMS documents endpoints for them.
 */
class SyncReservationToGrmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const ACTION_CREATED = 'reservation_created';
    private const ACTION_CHECKED_OUT = 'checked_out';
    private const ACTION_CANCELLED = 'reservation_cancelled';

    // ReservationController::refund() reuses this same action for BOTH a full
    // cancel-and-refund AND a partial date-shortening refund — the reservation's own
    // status (checked in handle()) is what actually distinguishes the two.
    private const ACTION_REFUNDED = 'reservation_refunded';

    private const RELEASE_ACTIONS = [self::ACTION_CHECKED_OUT, self::ACTION_CANCELLED, self::ACTION_REFUNDED];

    public int $tries = 5;

    public function __construct(
        private readonly int $reservationId,
        private readonly string $action,
    ) {}

    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    public function handle(GrmsClient $grms): void
    {
        if (!$grms->isEnabled()) {
            return;
        }

        $isCreate = $this->action === self::ACTION_CREATED;
        $isRelease = in_array($this->action, self::RELEASE_ACTIONS, true);

        if (!$isCreate && !$isRelease) {
            return;
        }

        $reservation = Reservation::with(['client', 'reservationRooms.room', 'reservationRooms.suite'])
            ->find($this->reservationId);

        if ($reservation === null) {
            return;
        }

        // A refund without a cancelled reservation is a partial/date-shortening
        // refund — the guest is still staying, so the room must NOT be released.
        if ($this->action === self::ACTION_REFUNDED
            && (int) $reservation->reservation_status !== Reservation::STATUS_CANCELLED) {
            return;
        }

        foreach ($reservation->reservationRooms as $reservationRoom) {
            $roomIdentity = $this->resolveRoomIdentity($reservationRoom);

            if ($roomIdentity === null) {
                continue;
            }

            if ($isCreate) {
                if ($reservation->client === null) {
                    continue;
                }

                $grms->setReservation([
                    'first_name' => $reservation->client->first_name,
                    'last_name' => $reservation->client->last_name,
                    'mobile' => GrmsClient::normalizeMobileNumber((string) $reservation->client->mobile),
                    'room_number' => $roomIdentity['room_number'],
                    'room_or_suite' => $roomIdentity['room_or_suite'],
                    'start_date' => $reservation->start_date,
                    'expire_date' => $reservation->expire_date,
                ]);
            } else {
                $grms->checkout($roomIdentity);
            }
        }
    }

    /**
     * @return array{room_number: string, room_or_suite: int}|null null if this
     *         room/suite has no number to report.
     */
    private function resolveRoomIdentity(ReservationRoom $reservationRoom): ?array
    {
        if ($reservationRoom->room_id !== null) {
            $roomOrSuite = 1;
            $roomNumber = $reservationRoom->room?->number;
        } else {
            $roomOrSuite = 2;
            $roomNumber = $reservationRoom->suite?->number;
        }

        if (blank($roomNumber)) {
            return null;
        }

        $grmsRoomNumber = GrmsClient::normalizeRoomNumber((string) $roomNumber);

        if ($grmsRoomNumber === '') {
            return null;
        }

        return [
            'room_number' => $grmsRoomNumber,
            'room_or_suite' => $roomOrSuite,
        ];
    }
}
