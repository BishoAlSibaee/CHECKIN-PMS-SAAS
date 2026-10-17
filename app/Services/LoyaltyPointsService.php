<?php

namespace App\Services;

use App\Models\Client;
use App\Models\LoyaltyPointLedger;
use App\Models\Reservation;
use App\Models\ReservationPay;

/**
 * Guest loyalty points: 1 point earned per 10 currency units paid, redeemable
 * 1-for-1 as a cash discount on a reservation. See LoyaltyPointLedger for the
 * append-only history; Client::loyalty_points_balance is the cached running
 * total kept in sync with it.
 */
class LoyaltyPointsService
{
    private const POINTS_PER_CURRENCY_UNIT = 10;

    public function __construct(private readonly ReservationFinancialService $financialService) {}

    /**
     * Award points for a successful payment. Throws on failure — callers that
     * treat this as a best-effort side effect (e.g. the payment observer) are
     * responsible for catching and logging, same as the other side effects
     * already wired there (voucher creation, ZATCA credit notes).
     */
    public function awardForPayment(ReservationPay $payment): void
    {
        $reservation = $payment->reservation;
        if (! $reservation || ! $reservation->client_id) {
            return;
        }

        $points = intdiv((int) floor((float) $payment->pay), self::POINTS_PER_CURRENCY_UNIT);
        if ($points <= 0) {
            return;
        }

        $this->adjustBalance(
            (int) $reservation->client_id,
            $points,
            (int) $reservation->id,
            (int) $payment->id,
            null,
            'earned_on_payment'
        );
    }

    /** Claw back points for a refund, mirroring the earn formula. Throws on failure. */
    public function reverseForRefund(ReservationPay $refund): void
    {
        $reservation = $refund->reservation;
        if (! $reservation || ! $reservation->client_id) {
            return;
        }

        $points = intdiv((int) floor((float) $refund->pay), self::POINTS_PER_CURRENCY_UNIT);
        if ($points <= 0) {
            return;
        }

        $this->adjustBalance(
            (int) $reservation->client_id,
            -$points,
            (int) $reservation->id,
            (int) $refund->id,
            null,
            'clawed_back_on_refund'
        );
    }

    /**
     * Redeem points as a cash discount on a reservation's total.
     *
     * @return array{points_redeemed: int, discount_applied: float, new_balance: int, reservation_total: float}
     *
     * @throws \InvalidArgumentException
     */
    public function redeem(Reservation $reservation, int $points, int $userId): array
    {
        if ($points <= 0) {
            throw new \InvalidArgumentException('Points to redeem must be greater than zero.');
        }

        if (! $reservation->client_id) {
            throw new \InvalidArgumentException('Reservation has no client to redeem points for.');
        }

        $client = Client::find($reservation->client_id);
        if (! $client) {
            throw new \InvalidArgumentException('Client not found.');
        }

        if ($points > (int) $client->loyalty_points_balance) {
            throw new \InvalidArgumentException('Client does not have enough loyalty points.');
        }

        $discountValue = (float) $points; // 1 point = 1 currency unit, redemption side.
        $balanceDue = $reservation->balanceDue();

        if ($discountValue > $balanceDue + 0.005) {
            throw new \InvalidArgumentException('Redemption amount exceeds the reservation balance due.');
        }

        // Deduct the points first: if this fails, the reservation total is never
        // touched. Unlike awardForPayment/reverseForRefund (best-effort side
        // effects of a payment that already happened), a failed redemption here
        // must not silently report success — this throws instead of swallowing.
        $newBalance = $this->adjustBalance(
            (int) $client->id,
            -$points,
            (int) $reservation->id,
            null,
            $userId,
            'redeemed'
        );

        $this->financialService->applyManualDiscount($reservation, $discountValue);

        return [
            'points_redeemed' => $points,
            'discount_applied' => $discountValue,
            'new_balance' => $newBalance,
            'reservation_total' => (float) $reservation->total,
        ];
    }

    /** @throws \RuntimeException when the client can't be found */
    private function adjustBalance(int $clientId, int $delta, ?int $reservationId, ?int $reservationPayId, ?int $userId, string $reason): int
    {
        if ($delta === 0) {
            $client = Client::find($clientId);

            return (int) ($client->loyalty_points_balance ?? 0);
        }

        $client = Client::query()->lockForUpdate()->find($clientId);
        if (! $client) {
            throw new \RuntimeException("Client {$clientId} not found while adjusting loyalty points.");
        }

        $newBalance = max(0, (int) $client->loyalty_points_balance + $delta);
        $actualDelta = $newBalance - (int) $client->loyalty_points_balance;

        if ($actualDelta !== 0) {
            $client->loyalty_points_balance = $newBalance;
            $client->save();

            LoyaltyPointLedger::create([
                'client_id' => $clientId,
                'reservation_id' => $reservationId,
                'reservation_pay_id' => $reservationPayId,
                'points' => $actualDelta,
                'reason' => $reason,
                'user_id' => $userId,
            ]);
        }

        return $newBalance;
    }
}
