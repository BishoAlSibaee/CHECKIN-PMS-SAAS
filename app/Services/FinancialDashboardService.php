<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Models\ReservationPay;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class FinancialDashboardService
{
    private const TAX_RATE = 0.15;

    public function __construct(
        private RevenueAccrualService $revenueAccrualService
    ) {}

    /**
     * @return array{earliest_charge_date: ?string, earliest_payment_date: ?string}
     */
    public function bounds(): array
    {
        $charge = ReservationDailyCharge::query()
            ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
            ->where('reservations.reservation_status', 1)
            ->min('reservation_daily_charges.charge_date');

        $payment = ReservationPay::query()
            ->join('reservations', 'reservation_pay.reservation_id', '=', 'reservations.id')
            ->whereIn('reservations.reservation_status', [1, 2])
            ->min('reservation_pay.created_at');

        return [
            'earliest_charge_date' => $charge ? Carbon::parse($charge)->toDateString() : null,
            'earliest_payment_date' => $payment ? Carbon::parse($payment)->toDateString() : null,
        ];
    }

    public function build(Carbon $start, Carbon $end): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->endOfDay();
        $startDate = $start->copy()->startOfDay();
        $endDate = $end->copy()->startOfDay();

        $accrual = $this->revenueAccrualService->calculate('total', null, $startDate, $endDate, false);
        $current = $accrual['current'];
        $cash = $this->cashForPeriod($startDate, $endDate);

        $days = $startDate->diffInDays($endDate) + 1;
        $compareEnd = $startDate->copy()->subDay();
        $compareStart = $compareEnd->copy()->subDays(max(0, $days - 1));

        $compareAccrual = $this->revenueAccrualService->calculate(
            'total',
            null,
            $compareStart,
            $compareEnd,
            false
        );
        $compareCash = $this->cashForPeriod($compareStart, $compareEnd);

        $compareAccrualTotal = (float) ($compareAccrual['current']['total'] ?? 0);
        $compareCashNet = (float) ($compareCash['net_earnings'] ?? 0);
        $accrualTotal = (float) ($current['total'] ?? 0);
        $cashNet = (float) ($cash['net_earnings'] ?? 0);

        $granularity = $this->resolveGranularity($days);
        $series = $this->buildSeries($startDate, $endDate, $granularity);

        return [
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
                'days' => $days,
            ],
            'kpis' => [
                'accrual_total' => round($accrualTotal, 2),
                'subtotal' => round((float) ($current['subtotal'] ?? 0), 2),
                'tax' => round((float) ($current['tax'] ?? 0), 2),
                'nightly' => round((float) ($current['nightly'] ?? 0), 2),
                'monthly' => round((float) ($current['monthly'] ?? 0), 2),
                'room_nights' => (int) ($current['count'] ?? 0),
                'reservation_count' => (int) ($current['reservation_count'] ?? 0),
                'cash_in' => round((float) $cash['total_in'], 2),
                'cash_out' => round((float) $cash['total_out'], 2),
                'cash_net' => round($cashNet, 2),
                'ar_balance' => $this->totalAccountsReceivable($endDate),
            ],
            'comparison' => [
                'accrual_total' => round($compareAccrualTotal, 2),
                'cash_net' => round($compareCashNet, 2),
                'pct_accrual' => $this->percentChange($compareAccrualTotal, $accrualTotal),
                'pct_cash_net' => $this->percentChange($compareCashNet, $cashNet),
                'compare_start' => $compareStart->toDateString(),
                'compare_end' => $compareEnd->toDateString(),
            ],
            'series' => $series,
            'series_granularity' => $granularity,
            'recent_transactions' => $this->recentTransactions($startDate, $endDate, 20),
        ];
    }

    private function resolveGranularity(int $days): string
    {
        if ($days <= 31) {
            return 'day';
        }
        if ($days <= 366) {
            return 'week';
        }

        return 'month';
    }

    /**
     * @return array<int, array{bucket: string, accrual: float, cash_in: float, cash_out: float, cash_net: float}>
     */
    private function buildSeries(Carbon $start, Carbon $end, string $granularity): array
    {
        $buckets = $this->bucketKeys($start, $end, $granularity);
        $accrualByBucket = $this->accrualByBucket($start, $end, $granularity);
        $cashByBucket = $this->cashByBucket($start, $end, $granularity);

        $series = [];
        foreach ($buckets as $bucket) {
            $cash = $cashByBucket[$bucket] ?? ['cash_in' => 0.0, 'cash_out' => 0.0];
            $cashIn = (float) $cash['cash_in'];
            $cashOut = (float) $cash['cash_out'];

            $series[] = [
                'bucket' => $bucket,
                'accrual' => round((float) ($accrualByBucket[$bucket] ?? 0), 2),
                'cash_in' => round($cashIn, 2),
                'cash_out' => round($cashOut, 2),
                'cash_net' => round($cashIn - $cashOut, 2),
            ];
        }

        return $series;
    }

    /**
     * @return array<int, string>
     */
    private function bucketKeys(Carbon $start, Carbon $end, string $granularity): array
    {
        $keys = [];

        if ($granularity === 'day') {
            foreach (CarbonPeriod::create($start, $end) as $day) {
                $keys[] = $day->toDateString();
            }

            return $keys;
        }

        if ($granularity === 'week') {
            // Must match bucketForDate() which always uses Monday of the ISO week.
            $cursor = $start->copy()->startOfWeek(Carbon::MONDAY);
            $last = $end->copy()->startOfWeek(Carbon::MONDAY);
            while ($cursor->lte($last)) {
                $keys[] = $cursor->toDateString();
                $cursor->addWeek();
            }

            return $keys;
        }

        $cursor = $start->copy()->startOfMonth();
        while ($cursor->lte($end)) {
            $keys[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        return $keys;
    }

    /**
     * @return array<string, float>
     */
    private function accrualByBucket(Carbon $start, Carbon $end, string $granularity): array
    {
        $charges = ReservationDailyCharge::query()
            ->select([
                'reservation_daily_charges.*',
                'reservations.discount',
                'reservations.classification_discount',
                'reservations.extras',
                'reservations.penalties',
                'rooms.number as room_number',
            ])
            ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
            ->join('rooms', 'reservation_daily_charges.room_id', '=', 'rooms.id')
            ->where('reservations.reservation_status', 1)
            ->whereBetween('reservation_daily_charges.charge_date', [
                $start->toDateString(),
                $end->toDateString(),
            ])
            ->get();

        if ($charges->isEmpty()) {
            return [];
        }

        $reservationTotals = ReservationDailyCharge::query()
            ->whereIn('reservation_id', $charges->pluck('reservation_id')->unique())
            ->selectRaw('reservation_id, SUM(base_amount) as total_base')
            ->groupBy('reservation_id')
            ->pluck('total_base', 'reservation_id');

        $byBucket = [];

        foreach ($charges as $charge) {
            $reservationTotalBase = (float) ($reservationTotals[$charge->reservation_id] ?? 0);
            $weight = $reservationTotalBase > 0
                ? (float) $charge->base_amount / $reservationTotalBase
                : 0;

            $periodSubtotal = (float) $charge->base_amount
                - (((float) $charge->classification_discount + (float) $charge->discount) * $weight)
                + ((float) $charge->extras * $weight)
                + ((float) $charge->penalties * $weight);
            $revenue = $periodSubtotal + round($periodSubtotal * self::TAX_RATE, 2);

            $bucket = $this->bucketForDate(
                Carbon::parse($charge->charge_date),
                $granularity
            );
            $byBucket[$bucket] = ($byBucket[$bucket] ?? 0) + $revenue;
        }

        return $byBucket;
    }

    /**
     * @return array<string, array{cash_in: float, cash_out: float}>
     */
    private function cashByBucket(Carbon $start, Carbon $end, string $granularity): array
    {
        $rows = ReservationPay::query()
            ->select([
                'reservation_pay.pay',
                'reservation_pay.type',
                'reservation_pay.created_at',
            ])
            ->join('reservations', 'reservation_pay.reservation_id', '=', 'reservations.id')
            ->whereIn('reservations.reservation_status', [1, 2])
            ->whereBetween('reservation_pay.created_at', [
                $start->copy()->startOfDay(),
                $end->copy()->endOfDay(),
            ])
            ->get();

        $byBucket = [];

        foreach ($rows as $row) {
            $bucket = $this->bucketForDate(Carbon::parse($row->created_at), $granularity);
            if (! isset($byBucket[$bucket])) {
                $byBucket[$bucket] = ['cash_in' => 0.0, 'cash_out' => 0.0];
            }
            if ((int) $row->type === ReservationPay::TYPE_PAYMENT) {
                $byBucket[$bucket]['cash_in'] += (float) $row->pay;
            } else {
                $byBucket[$bucket]['cash_out'] += (float) $row->pay;
            }
        }

        return $byBucket;
    }

    private function bucketForDate(Carbon $date, string $granularity): string
    {
        if ($granularity === 'day') {
            return $date->toDateString();
        }
        if ($granularity === 'week') {
            return $date->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        }

        return $date->format('Y-m');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentTransactions(Carbon $start, Carbon $end, int $limit): array
    {
        return ReservationPay::query()
            ->select('reservation_pay.*')
            ->join('reservations', 'reservation_pay.reservation_id', '=', 'reservations.id')
            ->whereIn('reservations.reservation_status', [1, 2])
            ->whereBetween('reservation_pay.created_at', [
                $start->copy()->startOfDay(),
                $end->copy()->endOfDay(),
            ])
            ->orderByDesc('reservation_pay.created_at')
            ->limit($limit)
            ->get()
            ->map(fn (ReservationPay $pay) => [
                'id' => $pay->id,
                'reservation_id' => $pay->reservation_id,
                'type' => (int) $pay->type,
                'pay' => round((float) $pay->pay, 2),
                'created_at' => $pay->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{total_in: float, total_out: float, net_earnings: float}
     */
    private function cashForPeriod(Carbon $start, Carbon $end): array
    {
        $bounds = \App\Support\ReservationCashQuery::cashPeriodBounds($start, $end);
        if ($bounds === null) {
            return ['total_in' => 0.0, 'total_out' => 0.0, 'net_earnings' => 0.0];
        }

        [$periodStart, $periodEnd] = $bounds;

        $totalIn = (float) \App\Support\ReservationCashQuery::paymentQuery()
            ->whereBetween('reservation_pay.created_at', [$periodStart, $periodEnd])
            ->sum('reservation_pay.pay');

        $totalOut = (float) \App\Support\ReservationCashQuery::refundQuery()
            ->whereBetween('reservation_pay.created_at', [$periodStart, $periodEnd])
            ->sum('reservation_pay.pay');

        return [
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'net_earnings' => $totalIn - $totalOut,
        ];
    }

    private function totalAccountsReceivable(Carbon $asOf): float
    {
        $total = 0.0;

        Reservation::with('payments')
            ->where('reservation_status', 1)
            ->get()
            ->each(function (Reservation $reservation) use (&$total) {
                $total += $this->reservationBalanceDue($reservation);
            });

        return round($total, 2);
    }

    private function reservationBalanceDue(Reservation $reservation): float
    {
        $total = (float) $reservation->total;

        if ($total <= 0 && (float) $reservation->subtotal > 0) {
            $total = round((float) $reservation->subtotal * (1 + self::TAX_RATE), 2);
        }

        return max(0, $total - $this->reservationPaidNet($reservation));
    }

    private function reservationPaidNet(Reservation $reservation): float
    {
        $paid = (float) $reservation->payments
            ->where('type', ReservationPay::TYPE_PAYMENT)
            ->sum('pay');
        $refunded = (float) $reservation->payments
            ->where('type', ReservationPay::TYPE_REFUND)
            ->sum('pay');

        return $paid - $refunded;
    }

    private function percentChange(float $previous, float $current): ?float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
