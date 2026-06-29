<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Models\ReservationPay;
use App\Services\Accounting\AccountingPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationFinancialService
{
    private const TAX_RATE = 0.15;

    public function __construct(
        private AccountingPostingService $accountingPostingService,
        private HotelLivePublisher $livePublisher,
    ) {
    }

    public function requestNeedsPricingRecalculation(Request $request, ?Reservation $reservation = null): bool
    {
        if ($request->filled('start_date') || $request->filled('expire_date')) {
            return true;
        }

        if (!$reservation) {
            return false;
        }

        foreach (['discount', 'extras', 'penalties'] as $field) {
            if (!$request->has($field)) {
                continue;
            }

            $newValue = (float) $request->input($field);
            $oldValue = (float) $reservation->{$field};

            if (abs($newValue - $oldValue) > 0.005) {
                return true;
            }
        }

        return false;
    }

    /**
     * Align reservation totals with persisted nightly charge lines (canonical).
     *
     * @param  bool  $preservePaidInFull  When true, do not raise total above what was already paid in full.
     */
    public function syncTotalsFromDailyCharges(Reservation $reservation, bool $preservePaidInFull = false): void
    {
        $reservation->loadMissing(['payments', 'reservationRooms']);

        $roomIds = $reservation->reservationRooms->pluck('id')->filter()->all();
        if ($roomIds !== []) {
            ReservationDailyCharge::query()
                ->where('reservation_id', $reservation->id)
                ->whereNotIn('reservation_room_id', $roomIds)
                ->delete();
        }

        $base = (float) ReservationDailyCharge::query()
            ->where('reservation_id', $reservation->id)
            ->sum('base_amount');

        if ($base <= 0) {
            return;
        }

        $oldTotal = (float) $reservation->total;
        $paidNet = $reservation->paidNetAmount();

        $newSubtotal = round(
            $base
            - (float) $reservation->discount
            + (float) $reservation->extras
            + (float) $reservation->penalties,
            2
        );
        $newTaxes = round($newSubtotal * self::TAX_RATE, 2, PHP_ROUND_HALF_UP);
        $newTotal = round($newSubtotal + $newTaxes, 2);

        $wasFullyPaid = $paidNet >= $oldTotal - 0.005;

        // Never inflate total after the guest already settled the prior total in full.
        if ($wasFullyPaid && $newTotal > $oldTotal + 0.005) {
            return;
        }

        $reservation->base_price = round($base, 2);
        $reservation->subtotal = $newSubtotal;
        $reservation->taxes = $newTaxes;
        $reservation->total = $newTotal;
    }

    /**
     * Record a payment and post accounting. Returns null when nothing is due.
     */
    public function recordPayment(
        Reservation $reservation,
        float $amount,
        int $userId,
        int $type = ReservationPay::TYPE_PAYMENT
    ): ?ReservationPay {
        if (Reservation::isCancelled((int) $reservation->reservation_status)) {
            throw new \InvalidArgumentException('Cannot add payment to a cancelled reservation.');
        }

        $reservation->loadMissing('payments');
        $balanceDue = $reservation->balanceDue();

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        if ($amount > $balanceDue + 0.005) {
            throw new \InvalidArgumentException('Payment amount exceeds the remaining balance.');
        }

        $payment = ReservationPay::create([
            'reservation_id' => $reservation->id,
            'pay'            => round($amount, 2),
            'type'           => $type,
            'user_id'        => $userId,
        ]);

        if ($type === ReservationPay::TYPE_PAYMENT) {
            $reservation->load('payments');
            $reservation->syncConfirmationIfFullyPaid();
        }

        $this->accountingPostingService->postPayment($payment);

        $this->livePublisher->publishPaymentChanged((int) $reservation->id);

        return $payment;
    }

    /**
     * Pay the exact remaining balance for one reservation.
     *
     * @return array{reservation_id: int, amount: float, payment_id: int}|null
     */
    public function collectOutstandingForReservation(Reservation $reservation, int $userId): ?array
    {
        $reservation->loadMissing('payments');
        $balanceDue = $reservation->balanceDue();

        if ($balanceDue <= 0.005) {
            return null;
        }

        $payment = $this->recordPayment($reservation, $balanceDue, $userId);

        return [
            'reservation_id' => $reservation->id,
            'amount' => round($balanceDue, 2),
            'payment_id' => $payment->id,
        ];
    }

    /**
     * Collect all outstanding balances (idempotent — skips already paid).
     *
     * @return array{
     *   collected_count: int,
     *   collected_total: float,
     *   skipped_count: int,
     *   items: array<int, array{reservation_id: int, amount: float, payment_id: int}>
     * }
     */
    public function collectAllOutstanding(int $userId): array
    {
        $this->reconcileAllReservationTotals();

        $items = [];
        $collectedTotal = 0.0;
        $skipped = 0;

        Reservation::query()
            ->with('payments')
            ->excludingCancelled()
            ->whereIn('reservation_status', Reservation::cashReportStatuses())
            ->withPositiveBalance()
            ->orderBy('id')
            ->chunkById(50, function ($reservations) use ($userId, &$items, &$collectedTotal, &$skipped) {
                foreach ($reservations as $reservation) {
                    DB::transaction(function () use ($reservation, $userId, &$items, &$collectedTotal, &$skipped) {
                        $fresh = Reservation::with('payments')->lockForUpdate()->find($reservation->id);
                        if (!$fresh) {
                            return;
                        }

                        $result = $this->collectOutstandingForReservation($fresh, $userId);
                        if ($result === null) {
                            $skipped++;

                            return;
                        }

                        $items[] = $result;
                        $collectedTotal += $result['amount'];
                    });
                }
            });

        if ($items !== []) {
            $this->livePublisher->publish([
                HotelLivePublisher::SCOPE_COLLECTIONS,
                HotelLivePublisher::SCOPE_OCCUPANCY_BOARD,
                HotelLivePublisher::SCOPE_DASHBOARD,
                HotelLivePublisher::SCOPE_RESERVATIONS,
            ], 'collect_all_completed');
        }

        return [
            'collected_count' => count($items),
            'collected_total' => round($collectedTotal, 2),
            'skipped_count' => $skipped,
            'items' => $items,
        ];
    }

    /**
     * Reconcile stored totals with daily charges for every non-cancelled reservation.
     *
     * @return array{reconciled: int, confirmed: int}
     */
    public function reconcileAllReservationTotals(): array
    {
        $reconciled = 0;
        $confirmed = 0;

        Reservation::query()
            ->excludingCancelled()
            ->with(['payments', 'reservationRooms'])
            ->orderBy('id')
            ->chunkById(50, function ($reservations) use (&$reconciled, &$confirmed) {
                foreach ($reservations as $reservation) {
                    $beforeTotal = (float) $reservation->total;
                    $beforeStatus = (int) $reservation->reservation_status;

                    $this->syncTotalsFromDailyCharges($reservation, preservePaidInFull: true);

                    if (
                        abs((float) $reservation->total - $beforeTotal) > 0.005
                        || (int) $reservation->reservation_status !== $beforeStatus
                    ) {
                        $reservation->save();
                    }

                    if ($reservation->syncConfirmationIfFullyPaid()) {
                        $confirmed++;
                    }

                    $reconciled++;
                }
            });

        return ['reconciled' => $reconciled, 'confirmed' => $confirmed];
    }
}
