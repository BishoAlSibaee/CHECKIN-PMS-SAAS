<?php

namespace App\Services;

use App\Exceptions\RefundNotAllowedException;
use App\Models\RefundPolicy;
use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use Carbon\Carbon;

class RefundPolicyService
{
    public const TAX_RATE = 0.15;

    /**
     * @return array<string, mixed>
     */
    public function preview(Reservation $reservation, ?string $newExpireDate = null): array
    {
        $reservation->loadMissing(['payments', 'client']);

        $this->assertRefundable($reservation, $newExpireDate);

        $context = $this->buildContext($reservation, $newExpireDate);
        $policy = $this->resolvePolicy($reservation, $context);

        if (! $policy) {
            throw new RefundNotAllowedException(
                'No refund policy applies to this reservation.'
            );
        }

        $amount = $this->calculateRefundAmount($reservation, $policy, $context);

        if ($amount <= 0) {
            throw new RefundNotAllowedException(
                'Based on cancellation policy, no refund amount due at this time.'
            );
        }

        $resolvedThreshold = $policy->resolveThresholdDays((int) $context['total_nights']);

        return [
            'policy' => $policy,
            'refund_amount' => $amount,
            'breakdown' => array_merge($context, [
                'policy_id' => $policy->id,
                'policy_name' => $policy->name,
                'refund_percent' => (float) $policy->refund_percent,
                'refund_basis' => $policy->refund_basis ?? 'total',
                'threshold_mode' => $policy->threshold_mode ?? RefundPolicy::THRESHOLD_FIXED_DAYS,
                'threshold_percent' => $policy->threshold_percent !== null
                    ? (float) $policy->threshold_percent
                    : null,
                'resolved_threshold_days' => $resolvedThreshold,
                'partial_cancel' => $newExpireDate !== null,
            ]),
        ];
    }

    public function assertRefundable(Reservation $reservation, ?string $newExpireDate = null): void
    {
        if (Reservation::isCancelled((int) $reservation->reservation_status)) {
            throw new RefundNotAllowedException('Reservation already cancelled, cannot refund.');
        }

        if ($newExpireDate === null && $reservation->expire_date < Carbon::today()->toDateString()) {
            throw new RefundNotAllowedException('Cannot refund a completed stay.');
        }

        if ($this->netPaid($reservation) <= 0) {
            throw new RefundNotAllowedException('No payments available for refund.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildContext(Reservation $reservation, ?string $newExpireDate = null): array
    {
        $start = Carbon::parse($reservation->start_date)->startOfDay();
        $expire = Carbon::parse($reservation->expire_date)->startOfDay();
        $totalNights = (int) $start->diffInDays($expire);

        $evalMoment = $newExpireDate !== null
            ? Carbon::parse($newExpireDate)->startOfDay()
            : Carbon::now();

        $daysUntilStart = $evalMoment->lt($start) ? (int) $evalMoment->diffInDays($start, false) : 0;
        $daysSinceStart = $evalMoment->gte($start) ? (int) $start->diffInDays($evalMoment, false) : 0;
        $duringStay = $evalMoment->betweenIncluded($start, $expire);

        $netPaid = $this->netPaid($reservation);
        $total = (float) $reservation->total;
        $paymentStatus = $netPaid <= 0 ? 0 : ($netPaid >= $total ? 2 : 1);

        $context = [
            'net_paid' => round($netPaid, 2),
            'total' => round($total, 2),
            'payment_status' => $paymentStatus,
            'rent_type' => (int) $reservation->rent_type,
            'days_until_start' => $daysUntilStart,
            'days_since_start' => $daysSinceStart,
            'during_stay' => $duringStay,
            'total_nights' => $totalNights,
            'remaining_base' => round($this->remainingNightsBase($reservation), 2),
            'tax_rate' => self::TAX_RATE,
            'policy_eval_date' => $evalMoment->toDateString(),
        ];

        if ($newExpireDate !== null) {
            $cancelled = $this->cancelledNightsContext($reservation, $newExpireDate);
            $context = array_merge($context, $cancelled);
        }

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelledNightsContext(Reservation $reservation, string $newExpireDate): array
    {
        $newExpire = Carbon::parse($newExpireDate)->startOfDay();
        $currentExpire = Carbon::parse($reservation->expire_date)->startOfDay();

        $cancelledBase = (float) ReservationDailyCharge::query()
            ->where('reservation_id', $reservation->id)
            ->where('charge_date', '>=', $newExpire->toDateString())
            ->where('charge_date', '<', $currentExpire->toDateString())
            ->sum('base_amount');

        $cancelledCount = (int) ReservationDailyCharge::query()
            ->where('reservation_id', $reservation->id)
            ->where('charge_date', '>=', $newExpire->toDateString())
            ->where('charge_date', '<', $currentExpire->toDateString())
            ->distinct()
            ->count('charge_date');

        return [
            'new_expire_date' => $newExpire->toDateString(),
            'cancelled_nights_count' => $cancelledCount,
            'cancelled_nights_base' => round($cancelledBase, 2),
        ];
    }

    /**
     * Nights removed when reservation dates shrink (later arrival and/or earlier departure).
     *
     * @return array{cancelled_nights_count: int, cancelled_nights_base: float}
     */
    public function removedNightsContextForDateChange(
        int $reservationId,
        string $oldStart,
        string $oldExpire,
        string $newStart,
        string $newExpire
    ): array {
        $oldStartDate = Carbon::parse($oldStart)->startOfDay()->toDateString();
        $oldExpireDate = Carbon::parse($oldExpire)->startOfDay()->toDateString();
        $newStartDate = Carbon::parse($newStart)->startOfDay()->toDateString();
        $newExpireDate = Carbon::parse($newExpire)->startOfDay()->toDateString();

        $query = ReservationDailyCharge::query()
            ->where('reservation_id', $reservationId)
            ->where('charge_date', '>=', $oldStartDate)
            ->where('charge_date', '<', $oldExpireDate)
            ->where(function ($q) use ($newStartDate, $newExpireDate) {
                $q->where('charge_date', '<', $newStartDate)
                    ->orWhere('charge_date', '>=', $newExpireDate);
            });

        return [
            'cancelled_nights_count' => (int) (clone $query)->distinct()->count('charge_date'),
            'cancelled_nights_base' => round((float) (clone $query)->sum('base_amount'), 2),
        ];
    }

    /**
     * @param  array{cancelled_nights_count?: int, cancelled_nights_base?: float}  $removedContext
     */
    public function refundAmountForRemovedNights(Reservation $reservation, array $removedContext): float
    {
        if ((int) ($removedContext['cancelled_nights_count'] ?? 0) === 0) {
            return 0.0;
        }

        $reservation->loadMissing('payments');
        $context = array_merge($this->buildContext($reservation), $removedContext);
        $policy = $this->resolvePolicy($reservation, $context);
        if (! $policy) {
            return 0.0;
        }

        return $this->calculateRefundAmount($reservation, $policy, $context);
    }

    public function resolvePolicy(Reservation $reservation, ?array $context = null): ?RefundPolicy
    {
        $context ??= $this->buildContext($reservation);
        $totalNights = (int) $context['total_nights'];

        $candidates = RefundPolicy::query()
            ->where(function ($q) use ($reservation) {
                $q->whereNull('rent_type')
                    ->orWhere('rent_type', (int) $reservation->rent_type);
            })
            ->get();

        $matched = $candidates->filter(function (RefundPolicy $policy) use ($context, $totalNights) {
            if (! $policy->matchesPaymentContext($context)) {
                return false;
            }

            return $policy->matchesTimingContext($context, $totalNights);
        });

        return $matched
            ->sort(function (RefundPolicy $a, RefundPolicy $b) use ($totalNights) {
                $byThreshold = $b->effectiveThresholdDays($totalNights) <=> $a->effectiveThresholdDays($totalNights);
                if ($byThreshold !== 0) {
                    return $byThreshold;
                }

                $byPriority = ((int) ($b->priority ?? 0)) <=> ((int) ($a->priority ?? 0));
                if ($byPriority !== 0) {
                    return $byPriority;
                }

                $byRefundPercent = (float) $a->refund_percent <=> (float) $b->refund_percent;
                if ($byRefundPercent !== 0) {
                    return $byRefundPercent;
                }

                return ((int) $b->id) <=> ((int) $a->id);
            })
            ->first();
    }

    public function calculateRefundAmount(
        Reservation $reservation,
        RefundPolicy $policy,
        ?array $context = null
    ): float {
        $context ??= $this->buildContext($reservation);
        $netPaid = $context['net_paid'];
        $percent = (float) $policy->refund_percent;

        if (isset($context['cancelled_nights_base'])) {
            $raw = ($context['cancelled_nights_base'] * (1 + self::TAX_RATE)) * ($percent / 100);

            return round(min($raw, $netPaid), 2);
        }

        $basis = $policy->refund_basis ?? 'total';

        $raw = match ($basis) {
            'remaining_nights' => ($context['remaining_base'] * (1 + self::TAX_RATE)) * ($percent / 100),
            'paid_net' => min($netPaid, ((float) $reservation->total) * ($percent / 100)),
            default => ((float) $reservation->total) * ($percent / 100),
        };

        return round(min($raw, $netPaid), 2);
    }

    public function netPaid(Reservation $reservation): float
    {
        return $reservation->paidNetAmount();
    }

    private function remainingNightsBase(Reservation $reservation): float
    {
        $today = Carbon::today()->toDateString();

        return (float) ReservationDailyCharge::query()
            ->where('reservation_id', $reservation->id)
            ->where('charge_date', '>=', $today)
            ->sum('base_amount');
    }
}
