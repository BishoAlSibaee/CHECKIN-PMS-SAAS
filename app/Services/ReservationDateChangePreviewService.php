<?php

namespace App\Services;

use App\Models\Reservation;
use Carbon\Carbon;

class ReservationDateChangePreviewService
{
    public function __construct(
        private PricingEngine $pricingEngine,
        private GuestClassificationDiscountService $guestClassificationDiscountService,
        private RefundPolicyService $refundPolicyService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(Reservation $reservation, string $newStart, string $newExpire): array
    {
        $reservation->loadMissing(['payments', 'reservationRooms.room']);

        $oldStart = (string) $reservation->start_date;
        $oldExpire = (string) $reservation->expire_date;
        $newStartDate = Carbon::parse($newStart)->startOfDay()->toDateString();
        $newExpireDate = Carbon::parse($newExpire)->startOfDay()->toDateString();

        $oldNights = (int) Carbon::parse($oldStart)->diffInDays(Carbon::parse($oldExpire));
        $newNights = (int) Carbon::parse($newStartDate)->diffInDays(Carbon::parse($newExpireDate));

        $currentTotals = $this->totalsFromReservation($reservation);
        $projectedTotals = $this->projectTotalsForDates($reservation, $newStartDate, $newExpireDate);

        $paidNet = round($reservation->paidNetAmount(), 2);
        $currentBalance = round($reservation->balanceDue(), 2);
        $newBalance = round(max(0, $projectedTotals['total'] - $paidNet), 2);
        $totalDelta = round($projectedTotals['total'] - $currentTotals['total'], 2);

        $removedContext = $this->refundPolicyService->removedNightsContextForDateChange(
            (int) $reservation->id,
            $oldStart,
            $oldExpire,
            $newStartDate,
            $newExpireDate
        );

        $refundAmount = 0.0;
        if ((int) ($removedContext['cancelled_nights_count'] ?? 0) > 0) {
            $overpaid = round($paidNet - $projectedTotals['total'], 2);
            if ($overpaid > 0.005) {
                $refundAmount = $overpaid;
            }
        }

        $financialAction = 'no_change';
        if ($refundAmount > 0.005) {
            $financialAction = 'refund';
        } elseif ($newBalance > $currentBalance + 0.005) {
            $financialAction = 'additional_charge';
        } elseif ($newBalance < $currentBalance - 0.005 && $refundAmount <= 0.005) {
            $financialAction = 'balance_reduced';
        }

        return [
            'current' => [
                'start_date' => $oldStart,
                'expire_date' => $oldExpire,
                'nights' => $oldNights,
                'subtotal' => $currentTotals['subtotal'],
                'taxes' => $currentTotals['taxes'],
                'total' => $currentTotals['total'],
                'paid_net' => $paidNet,
                'balance_due' => $currentBalance,
            ],
            'projected' => [
                'start_date' => $newStartDate,
                'expire_date' => $newExpireDate,
                'nights' => $newNights,
                'subtotal' => $projectedTotals['subtotal'],
                'taxes' => $projectedTotals['taxes'],
                'total' => $projectedTotals['total'],
                'paid_net' => $paidNet,
                'balance_due' => $newBalance,
            ],
            'delta' => [
                'nights' => $newNights - $oldNights,
                'total' => $totalDelta,
                'balance_due' => round($newBalance - $currentBalance, 2),
            ],
            'removed_nights_count' => (int) ($removedContext['cancelled_nights_count'] ?? 0),
            'refund_amount' => $refundAmount,
            'financial_action' => $financialAction,
            'will_post_accrual_adjustment' => $totalDelta !== 0.0,
        ];
    }

    /**
     * @return array{subtotal: float, taxes: float, total: float}
     */
    private function totalsFromReservation(Reservation $reservation): array
    {
        return [
            'subtotal' => round((float) $reservation->subtotal, 2),
            'taxes' => round((float) $reservation->taxes, 2),
            'total' => round((float) $reservation->total, 2),
        ];
    }

    /**
     * @return array{subtotal: float, taxes: float, total: float}
     */
    private function projectTotalsForDates(Reservation $reservation, string $startDate, string $expireDate): array
    {
        $priceMode = (int) ($reservation->price_calculation_mode ?? 0);
        $totalBase = 0.0;

        foreach ($reservation->reservationRooms as $resRoom) {
            if (! $resRoom->room) {
                continue;
            }

            $lines = $this->pricingEngine->buildDailyBreakdown(
                $resRoom->room,
                $startDate,
                $expireDate,
                (int) $reservation->rent_type,
                $priceMode
            );
            $totalBase += $this->pricingEngine->sumBaseAmount($lines);
        }

        $scratch = clone $reservation;
        $scratch->base_price = round($totalBase, 2);
        $discountAmounts = $this->guestClassificationDiscountService->recalculateForReservation($scratch);

        return $this->guestClassificationDiscountService->calculateTotals(
            round($totalBase, 2),
            $discountAmounts['classification_discount'],
            $discountAmounts['discount'],
            (float) $reservation->extras,
            (float) $reservation->penalties
        );
    }
}
