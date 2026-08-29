<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Services\Accounting\AccountingPostingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReservationShortenService
{
    public function __construct(
        private ReservationRoomStatusService $roomStatusService,
        private AccountingPostingService $accountingPostingService
    ) {}

    /**
     * @throws \InvalidArgumentException
     */
    public function validateShorten(Reservation $reservation, Carbon $newExpire): void
    {
        if (\App\Models\Reservation::isCancelled((int) $reservation->reservation_status)) {
            throw new \InvalidArgumentException('Cannot shorten a cancelled reservation.');
        }

        $today = Carbon::today()->startOfDay();
        $start = Carbon::parse($reservation->start_date)->startOfDay();
        $currentExpire = Carbon::parse($reservation->expire_date)->startOfDay();

        if ($currentExpire->lt($today)) {
            throw new \InvalidArgumentException('Cannot shorten a completed stay.');
        }

        if ($newExpire->lte($start)) {
            throw new \InvalidArgumentException('New checkout must be after check-in date.');
        }

        if ($newExpire->lte($today)) {
            throw new \InvalidArgumentException('New checkout must be after today.');
        }

        if ($newExpire->gte($currentExpire)) {
            throw new \InvalidArgumentException('New checkout must be before the current departure date.');
        }
    }

    public function applyShorten(Reservation $reservation, Carbon $newExpire): Reservation
    {
        $this->validateShorten($reservation, $newExpire);

        $apply = function () use ($reservation, $newExpire): Reservation {
            $reservation->loadMissing('reservationRooms');

            $newExpireStr = $newExpire->toDateString();

            ReservationDailyCharge::query()
                ->where('reservation_id', $reservation->id)
                ->where('charge_date', '>=', $newExpireStr)
                ->delete();

            $totalBase = (float) ReservationDailyCharge::query()
                ->where('reservation_id', $reservation->id)
                ->sum('base_amount');

            $startDate = Carbon::parse($reservation->start_date)->startOfDay();
            $reservation->expire_date = $newExpireStr;
            $reservation->nights = (int) $startDate->diffInDays($newExpire);
            $reservation->base_price = round($totalBase, 2);
            app(GuestClassificationDiscountService::class)->applyFinancialsAfterRecalculation($reservation, $totalBase);
            $reservation->save();

            $this->accountingPostingService->syncAccrualForReservation((int) $reservation->id);

            $this->roomStatusService->syncForReservation($reservation->fresh(['reservationRooms']));

            return $reservation->fresh(['payments', 'reservationRooms']);
        };

        return DB::transactionLevel() > 0 ? $apply() : DB::transaction($apply);
    }
}
