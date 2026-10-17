<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Services\Cache\DashboardSummaryCache;
use App\Services\CollectionsService;
use App\Services\RevenueAccrualService;
use App\Services\RoomOccupancyService;
use Carbon\Carbon;

class DashboardController extends Controller
{
    private const ROLLING_PERIOD_DAYS = 30;

    public function __construct(
        private RoomOccupancyService $occupancyService,
        private RevenueAccrualService $revenueAccrualService,
        private CollectionsService $collectionsService
    ) {}

    public function summary()
    {
        try {
            $today = Carbon::today();

            $payload = DashboardSummaryCache::remember($today, function () use ($today) {
                return $this->buildSummaryPayload($today);
            });

            return \SuccessData('Dashboard summary retrieved', $payload);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSummaryPayload(Carbon $today): array
    {
        $periodStart = $this->rollingPeriodStart($today);
        $periodEnd = $this->rollingPeriodEnd($today);

        $occupancy = $this->occupancyService->summaryForDate($today);

        $periodRevenue = $this->revenueAccrualService->calculate(
            'total',
            null,
            $periodStart,
            $periodEnd,
            false
        );

        $periodRoomNights = (int) ($periodRevenue['current']['count'] ?? 0);
        $periodAccrualBase = (float) ($periodRevenue['current']['total_base'] ?? 0);
        $avgDailyRate = $periodRoomNights > 0
            ? round($periodAccrualBase / $periodRoomNights, 2)
            : 0.0;

        $cash = $this->earningsForPeriod($periodStart, $periodEnd);

        $weeklyAccrual = $this->weeklyAccrual($today);

        $recent = Reservation::with(['client', 'reservationRooms.room'])
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn ($r) => $this->formatRecentReservation($r));

        return [
            'date' => $today->toDateString(),
            'occupancy' => $occupancy,
            'check_ins_today' => $this->occupancyService->checkInsToday($today),
            'check_outs_today' => $this->occupancyService->checkOutsToday($today),
            'overdue_checkouts' => $this->occupancyService->overdueCheckoutsCount($today),
            'pending_checkouts' => $this->occupancyService->pendingCheckoutsCount($today),
            'arrivals_today' => $this->occupancyService->arrivalsToday($today),
            'departures_today' => $this->occupancyService->departuresToday($today),
            'month_accrual_revenue' => round($periodRevenue['current']['total'] ?? 0, 2),
            'month_accrual_base' => round($periodAccrualBase, 2),
            'month_room_nights' => $periodRoomNights,
            'avg_daily_rate' => $avgDailyRate,
            'month_cash_in' => round($cash['total_in'], 2),
            'month_cash_out' => round($cash['total_out'], 2),
            'month_cash_net' => round($cash['net_earnings'], 2),
            'accrual_period_start' => $periodStart->toDateString(),
            'accrual_period_end' => $periodEnd->toDateString(),
            'weekly_accrual' => $weeklyAccrual,
            'recent_reservations' => $recent,
            'collections' => $this->collectionsService->summarize($today),
        ];
    }

    private function rollingPeriodStart(Carbon $today): Carbon
    {
        return $today->copy()->subDays(self::ROLLING_PERIOD_DAYS - 1)->startOfDay();
    }

    private function rollingPeriodEnd(Carbon $today): Carbon
    {
        return $today->copy()->endOfDay();
    }

    private function weeklyAccrual(Carbon $today): array
    {
        $weekStart = $today->copy()->subDays(6)->startOfDay();
        $weekEnd = $today->copy()->endOfDay();
        $totalsByDate = $this->revenueAccrualService->dailyRevenueTotals($weekStart, $weekEnd);

        $labels = [];
        $values = [];

        for ($i = 6; $i >= 0; $i--) {
            $day = $today->copy()->subDays($i);
            $iso = $day->toDateString();
            $labels[] = $iso;
            $values[] = (float) ($totalsByDate[$iso] ?? 0.0);
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    private function earningsForPeriod(Carbon $start, Carbon $end): array
    {
        $bounds = \App\Support\ReservationCashQuery::cashPeriodBounds($start, $end);
        if ($bounds === null) {
            return ['total_in' => 0.0, 'total_out' => 0.0, 'net_earnings' => 0.0];
        }

        [$periodStart, $periodEnd] = $bounds;

        $payments = \App\Support\ReservationCashQuery::paymentQuery()
            ->whereBetween('reservation_pay.created_at', [$periodStart, $periodEnd])
            ->selectRaw('COALESCE(SUM(pay), 0) as total_in')
            ->value('total_in');

        $refunds = \App\Support\ReservationCashQuery::refundQuery()
            ->whereBetween('reservation_pay.created_at', [$periodStart, $periodEnd])
            ->selectRaw('COALESCE(SUM(pay), 0) as total_out')
            ->value('total_out');

        $totalIn = (float) $payments;
        $totalOut = (float) $refunds;

        return [
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'net_earnings' => $totalIn - $totalOut,
        ];
    }

    private function formatRecentReservation(Reservation $r): array
    {
        $room = $r->reservationRooms->first()?->room;
        $client = $r->client;

        return [
            'id' => $r->id,
            'guest' => $client
                ? trim(($client->first_name ?? '').' '.($client->last_name ?? ''))
                : '—',
            'room' => $room?->number ?? '—',
            'start_date' => $r->start_date,
            'expire_date' => $r->expire_date,
            'status' => (int) $r->reservation_status,
            'total' => round((float) $r->total, 2),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
