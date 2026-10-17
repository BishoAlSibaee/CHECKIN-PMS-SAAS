<?php

namespace Tests\Support;

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;
use PHPUnit\Framework\Assert;

trait RevenueSummaryExpectations
{
    protected function expectedBookedRoomNightsForDate(string $date): int
    {
        return (int) ReservationDailyCharge::query()
            ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
            ->where('reservations.reservation_status', 1)
            ->whereDate('reservation_daily_charges.charge_date', $date)
            ->count();
    }

    protected function expectedEarnedRoomNightsForDate(string $date): int
    {
        if (Carbon::parse($date)->startOfDay()->gt(Carbon::today()->startOfDay())) {
            return 0;
        }

        return $this->expectedBookedRoomNightsForDate($date);
    }

    protected function expectedActiveBookingsForDate(string $date): int
    {
        return Reservation::countActiveOnDate($date);
    }

    /** @deprecated Use expectedActiveBookingsForDate */
    protected function expectedCalendarBookingsForDate(string $date): int
    {
        return $this->expectedActiveBookingsForDate($date);
    }

    protected function expectedConfirmedStaysWithChargeForDate(string $date): int
    {
        return (int) ReservationDailyCharge::query()
            ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
            ->where('reservations.reservation_status', 1)
            ->whereDate('reservation_daily_charges.charge_date', $date)
            ->distinct()
            ->count('reservation_daily_charges.reservation_id');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function assertRevenueSummaryRowMatchesDatabase(array $row, string $context = ''): void
    {
        $date = (string) ($row['charge_date'] ?? '');
        $prefix = $context !== '' ? "{$context} ({$date}): " : "{$date}: ";

        Assert::assertSame(
            $this->expectedBookedRoomNightsForDate($date),
            (int) ($row['room_nights'] ?? -1),
            $prefix . 'booked room nights'
        );
        Assert::assertSame(
            $this->expectedEarnedRoomNightsForDate($date),
            (int) ($row['earned_room_nights'] ?? -1),
            $prefix . 'earned room nights'
        );
        Assert::assertSame(
            $this->expectedActiveBookingsForDate($date),
            (int) ($row['active_bookings'] ?? -1),
            $prefix . 'active bookings'
        );
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function assertRevenueSummaryReportMatchesCanonicalServices(
        array $report,
        Carbon $start,
        Carbon $end,
        string $context = ''
    ): void {
        $prefix = $context !== '' ? "{$context}: " : '';
        $accrual = app(RevenueAccrualService::class)->calculate('total', null, $start, $end, true);
        $detailsByDate = collect($accrual['details'] ?? [])->groupBy('charge_date');

        $expectedDays = (int) ($start->diffInDays($end) + 1);
        Assert::assertCount($expectedDays, $report['rows'] ?? [], $prefix . 'row count');

        foreach ($report['rows'] ?? [] as $row) {
            $this->assertRevenueSummaryRowMatchesDatabase($row, $context);

            $date = (string) $row['charge_date'];
            $expectedRevenue = round(collect($detailsByDate->get($date, collect()))->sum('revenue'), 2);
            Assert::assertEqualsWithDelta(
                $expectedRevenue,
                (float) ($row['revenue'] ?? 0),
                0.05,
                $prefix . "revenue on {$date}"
            );
        }

        $bookedTotal = (int) collect($report['rows'] ?? [])->sum('room_nights');
        $earnedTotal = (int) collect($report['rows'] ?? [])->sum('earned_room_nights');
        $revenueTotal = round(collect($report['rows'] ?? [])->sum('revenue'), 2);

        Assert::assertEqualsWithDelta(
            round((float) $accrual['current']['total'], 2),
            $revenueTotal,
            0.10,
            $prefix . 'sum(row revenue)'
        );
        Assert::assertSame((int) $accrual['current']['count'], $earnedTotal, $prefix . 'earned nights total');
        Assert::assertSame(
            (int) collect($report['summary'] ?? [])->firstWhere('label', 'Room nights (booked)')['value'],
            $bookedTotal,
            $prefix . 'booked nights summary'
        );
        Assert::assertSame(
            (int) collect($report['summary'] ?? [])->firstWhere('label', 'Room nights (earned)')['value'],
            $earnedTotal,
            $prefix . 'earned nights summary'
        );
    }
}
