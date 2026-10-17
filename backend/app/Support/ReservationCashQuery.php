<?php

namespace App\Support;

use App\Models\Reservation;
use App\Models\ReservationPay;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ReservationCashQuery
{
    /** Statuses included when listing payment/refund cash movements. */
    public static function cashMovementStatuses(): array
    {
        return [
            Reservation::STATUS_CONFIRMED,
            Reservation::STATUS_PENDING_PAYMENT,
            Reservation::STATUS_CANCELLED,
        ];
    }

    public static function applyCashMovementJoin(Builder $query): Builder
    {
        return $query->join('reservations', 'reservation_pay.reservation_id', '=', 'reservations.id')
            ->whereIn('reservations.reservation_status', self::cashMovementStatuses());
    }

    public static function paymentQuery(): Builder
    {
        return ReservationPay::query()
            ->tap(fn (Builder $q) => self::applyCashMovementJoin($q))
            ->where('reservation_pay.type', ReservationPay::TYPE_PAYMENT);
    }

    public static function refundQuery(): Builder
    {
        return ReservationPay::query()
            ->tap(fn (Builder $q) => self::applyCashMovementJoin($q))
            ->where('reservation_pay.type', ReservationPay::TYPE_REFUND);
    }

    /**
     * Cash reports only include movements through today (no future-dated payments).
     *
     * @return array{0: Carbon, 1: Carbon}|null null when the period is entirely in the future
     */
    public static function cashPeriodBounds(Carbon $start, Carbon $end): ?array
    {
        $periodStart = $start->copy()->startOfDay();
        $recognizedEnd = $end->copy()->endOfDay();
        $todayEnd = Carbon::today()->endOfDay();

        if ($recognizedEnd->gt($todayEnd)) {
            $recognizedEnd = $todayEnd;
        }

        if ($periodStart->gt($recognizedEnd)) {
            return null;
        }

        return [$periodStart, $recognizedEnd];
    }

    /** Payment timestamp for seeds/demo: never after today. */
    public static function capPaymentTimestampToToday(Carbon $candidate): Carbon
    {
        $today = Carbon::today()->startOfDay();

        return $candidate->copy()->startOfDay()->gt($today) ? $today : $candidate;
    }
}
