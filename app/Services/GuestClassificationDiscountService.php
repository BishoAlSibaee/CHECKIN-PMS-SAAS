<?php

namespace App\Services;

use App\Models\Client_Classifications;
use App\Models\Discount;
use App\Models\Guest_classification;
use App\Models\Reservation;

class GuestClassificationDiscountService
{
    private const TAX_RATE = 0.15;

    public function resolveForClient(int $clientId): ?ResolvedGuestDiscount
    {
        $clientClassification = Client_Classifications::where('client_id', $clientId)->first();

        if (! $clientClassification) {
            return null;
        }

        $classification = Guest_classification::query()
            ->with('discount')
            ->find($clientClassification->classifications_id);
        if (! (int) $classification->active) {
            return null;
        }

        if (! $classification->discount_id || ! $classification->discount) {
            return null;
        }

        $discount = $classification->discount;
        if (! (int) $discount->is_active) {
            return null;
        }

        return new ResolvedGuestDiscount(
            guestClassificationId: (int) $classification->id,
            classificationDiscountId: (int) $discount->id,
            discount: $discount,
            classification: $classification,
        );
    }

    public function computeAmount(Discount $rule, float $basePrice): float
    {
        if ($basePrice <= 0) {
            return 0.0;
        }

        $amount = 0.0;
        if ((int) $rule->is_percentage && $rule->percent !== null) {
            $amount += $basePrice * (float) $rule->percent / 100;
        }
        if ((int) $rule->is_fixed && $rule->fixed_amount !== null) {
            $amount += (float) $rule->fixed_amount;
        }

        return round(min($basePrice, max(0, $amount)), 2);
    }

    /**
     * @return array{classification: float, manual: float}
     */
    public function applyCap(float $classificationAmount, float $manualAmount, float $basePrice): array
    {
        $classification = round(min($basePrice, max(0, $classificationAmount)), 2);
        $remaining = max(0, $basePrice - $classification);
        $manual = round(min($remaining, max(0, $manualAmount)), 2);

        return [
            'classification' => $classification,
            'manual' => $manual,
        ];
    }

    /**
     * @return array{
     *     classification_discount: float,
     *     discount: float,
     *     guest_classification_id: int|null,
     *     classification_discount_id: int|null
     * }
     */
    public function resolveAmountsForClient(int $clientId, float $basePrice, float $manualDiscountRaw = 0): array
    {
        $resolved = $this->resolveForClient($clientId);
        $classificationRaw = $resolved
            ? $this->computeAmount($resolved->discount, $basePrice)
            : 0.0;

        $capped = $this->applyCap($classificationRaw, $manualDiscountRaw, $basePrice);

        return [
            'classification_discount' => $capped['classification'],
            'discount' => $capped['manual'],
            'guest_classification_id' => $resolved?->guestClassificationId,
            'classification_discount_id' => $resolved?->classificationDiscountId,
        ];
    }

    /**
     * @return array{classification_discount: float, discount: float}
     */
    public function recalculateForReservation(Reservation $reservation, ?float $manualOverride = null): array
    {
        $basePrice = (float) $reservation->base_price;
        $manual = $manualOverride ?? (float) $reservation->discount;

        if (! $reservation->classification_discount_id) {
            $capped = $this->applyCap(0, $manual, $basePrice);

            return [
                'classification_discount' => $capped['classification'],
                'discount' => $capped['manual'],
            ];
        }

        $discount = Discount::query()->find($reservation->classification_discount_id);
        if (! $discount) {
            $capped = $this->applyCap(0, $manual, $basePrice);

            return [
                'classification_discount' => $capped['classification'],
                'discount' => $capped['manual'],
            ];
        }

        $classificationRaw = $this->computeAmount($discount, $basePrice);
        $capped = $this->applyCap($classificationRaw, $manual, $basePrice);

        return [
            'classification_discount' => $capped['classification'],
            'discount' => $capped['manual'],
        ];
    }

    /**
     * @return array{subtotal: float, taxes: float, total: float}
     */
    public function calculateTotals(
        float $basePrice,
        float $classificationDiscount,
        float $manualDiscount,
        float $extras = 0,
        float $penalties = 0
    ): array {
        $subtotal = round(
            $basePrice - $classificationDiscount - $manualDiscount + $extras + $penalties,
            2
        );
        $taxes = round($subtotal * self::TAX_RATE, 2, PHP_ROUND_HALF_UP);
        $total = round($subtotal + $taxes, 2);

        return [
            'subtotal' => $subtotal,
            'taxes' => $taxes,
            'total' => $total,
        ];
    }

    public function applyFinancialsForNewBooking(
        Reservation $reservation,
        int $clientId,
        float $basePrice,
        float $manualDiscountRaw,
        float $extras,
        float $penalties
    ): void {
        $amounts = $this->resolveAmountsForClient($clientId, $basePrice, $manualDiscountRaw);

        $reservation->classification_discount = $amounts['classification_discount'];
        $reservation->discount = $amounts['discount'];
        $reservation->guest_classification_id = $amounts['guest_classification_id'];
        $reservation->classification_discount_id = $amounts['classification_discount_id'];
        $reservation->base_price = round($basePrice, 2);
        $reservation->extras = $extras;
        $reservation->penalties = $penalties;

        $totals = $this->calculateTotals(
            $basePrice,
            $amounts['classification_discount'],
            $amounts['discount'],
            $extras,
            $penalties
        );

        $reservation->subtotal = $totals['subtotal'];
        $reservation->taxes = $totals['taxes'];
        $reservation->total = $totals['total'];
    }

    public function applyFinancialsAfterRecalculation(
        Reservation $reservation,
        float $basePrice,
        ?float $manualOverride = null
    ): void {
        $reservation->base_price = round($basePrice, 2);
        $amounts = $this->recalculateForReservation($reservation, $manualOverride);

        $reservation->classification_discount = $amounts['classification_discount'];
        $reservation->discount = $amounts['discount'];

        $totals = $this->calculateTotals(
            $basePrice,
            $amounts['classification_discount'],
            $amounts['discount'],
            (float) $reservation->extras,
            (float) $reservation->penalties
        );

        $reservation->subtotal = $totals['subtotal'];
        $reservation->taxes = $totals['taxes'];
        $reservation->total = $totals['total'];
    }
}
