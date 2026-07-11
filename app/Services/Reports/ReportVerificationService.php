<?php

namespace App\Services\Reports;

use App\Models\JournalEntry;
use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Models\ReservationPay;
use App\Models\Room;
use App\Services\Accounting\FinancialAuditService;
use App\Services\Accounting\FinancialStatementService;
use App\Services\RevenueAccrualService;
use App\Services\RoomOccupancyService;
use Carbon\Carbon;

class ReportVerificationService
{
    private const TAX_RATE = 0.15;

    private const DELTA = 0.15;

    public function __construct(
        private ReportQueryService $reportQueryService,
        private RevenueAccrualService $revenueAccrualService,
        private FinancialStatementService $financialStatementService,
        private FinancialAuditService $financialAuditService,
        private RoomOccupancyService $occupancyService,
    ) {}

    /**
     * @return array<int, array{slug: string, pass: bool, message: string}>
     */
    public function verifyAll(?Carbon $start = null, ?Carbon $end = null): array
    {
        $start ??= Carbon::parse('2026-08-01');
        $end ??= Carbon::parse('2026-08-31');
        $params = [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ];

        $results = [];
        foreach (ReportCatalog::allSlugs() as $slug) {
            $results[] = $this->verifySlug($slug, $start, $end, $params);
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $params
     * @return array{slug: string, pass: bool, message: string}
     */
    public function verifySlug(string $slug, Carbon $start, Carbon $end, array $params): array
    {
        try {
            $runParams = $params;
            if ($slug === 'general-ledger' && empty($runParams['account_type']) && empty($runParams['account_id'])) {
                $runParams['account_type'] = 'asset';
            }

            $report = $this->reportQueryService->run($slug, $runParams);
            $message = match ($slug) {
                'overview' => $this->verifyOverview($report, $start, $end),
                'accrual-revenue' => $this->verifyAccrualRevenue($report, $start, $end),
                'revenue-summary' => $this->verifyRevenueSummary($report, $start, $end),
                'tax' => $this->verifyTax($report, $start, $end),
                'revpar' => $this->verifyRevpar($report, $start, $end),
                'occupancy' => $this->verifyOccupancy($report, $start, $end),
                'cash-box', 'payments-refunds' => $this->verifyCashReport($report, $start, $end, $slug),
                'accrual-cash-reconciliation' => $this->verifyReconciliation($report, $start, $end),
                'closing-package' => $this->verifyClosingPackage($report, $start, $end),
                'by-dimension' => $this->verifyByDimension($report, $start, $end),
                'peak-analysis' => $this->verifyPeakAnalysis($report, $start, $end),
                'adjustments' => $this->verifyAdjustments($report, $start, $end),
                'ar-aging' => $this->verifyArAging($report, $end),
                'chart-of-accounts' => $this->verifyChartOfAccounts($report, $start, $end),
                'trial-balance' => $this->verifyTrialBalance($report, $start, $end),
                'general-ledger' => $this->verifyGeneralLedger($report, $start, $end, $runParams),
                'cash-flow' => $this->verifyCashFlow($report, $start, $end),
                'journal-entries' => $this->verifyJournalEntries($report, $start, $end),
                'financial-audit-log' => $this->verifyFinancialAuditLog($report, $start, $end),
                'arrivals-departures' => $this->verifyArrivalsDepartures($report, $start, $end),
                'reservations-list' => $this->verifyReservationsList($report, $start, $end),
                'room-board' => $this->verifyRoomBoard($report, $end),
                default => 'Unknown slug',
            };

            $pass = ! str_starts_with($message, 'FAIL');

            return ['slug' => $slug, 'pass' => $pass, 'message' => $message];
        } catch (\Throwable $e) {
            return ['slug' => $slug, 'pass' => false, 'message' => 'FAIL: '.$e->getMessage()];
        }
    }

    /**
     * @return array<string, float|int>
     */
    public function dumpGolden(Carbon $start, Carbon $end): array
    {
        $accrual = $this->revenueAccrualService->calculate('total', null, $start, $end, false);
        $cash = $this->cashForPeriod($start, $end);

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'accrual_total' => round((float) $accrual['current']['total'], 2),
            'accrual_subtotal' => round((float) $accrual['current']['subtotal'], 2),
            'accrual_tax' => round((float) $accrual['current']['tax'], 2),
            'room_nights' => (int) $accrual['current']['count'],
            'cash_in' => round($cash['total_in'], 2),
            'cash_out' => round($cash['total_out'], 2),
            'cash_net' => round($cash['net_earnings'], 2),
            'ar_total' => $this->totalAccountsReceivable($end),
        ];
    }

    private function verifyOverview(array $report, Carbon $start, Carbon $end): string
    {
        $accrual = $this->revenueAccrualService->calculate('total', null, $start, $end, false);
        $cash = $this->cashForPeriod($start, $end);
        $expectedRevenue = round((float) $accrual['current']['total'], 2);
        $expectedCashNet = round($cash['net_earnings'], 2);
        $expectedAr = $this->totalAccountsReceivable($end);

        if (! $this->near($this->summaryFloat($report, 'Accrual revenue'), $expectedRevenue)) {
            return "FAIL: Accrual revenue {$this->summaryFloat($report, 'Accrual revenue')} vs {$expectedRevenue}";
        }
        if (! $this->near($this->summaryFloat($report, 'Net cash'), $expectedCashNet)) {
            return 'FAIL: Net cash mismatch';
        }
        if (! $this->near($this->summaryFloat($report, 'A/R balance'), $expectedAr)) {
            return 'FAIL: AR balance mismatch';
        }

        return 'PASS: overview accrual/cash/AR';
    }

    private function verifyAccrualRevenue(array $report, Carbon $start, Carbon $end): string
    {
        $accrual = $this->revenueAccrualService->calculate('total', null, $start, $end, true);
        $expected = round((float) $accrual['current']['total'], 2);
        $expectedNights = (int) $accrual['current']['count'];
        $summary = $this->summaryFloat($report, 'Total revenue');
        $rowSum = round(collect($report['rows'] ?? [])->sum(fn ($r) => (float) ($r['revenue'] ?? 0)), 2);
        $detailsByDate = collect($accrual['details'] ?? [])->groupBy('charge_date');

        if (! $this->near($summary, $expected) || ! $this->near($rowSum, $expected)) {
            return "FAIL: accrual-revenue summary {$summary} rows {$rowSum} vs {$expected}";
        }

        $expectedDays = (int) ($start->diffInDays($end) + 1);
        if (count($report['rows'] ?? []) !== $expectedDays) {
            return 'FAIL: accrual-revenue day row count';
        }

        $rowNights = (int) collect($report['rows'] ?? [])->sum('room_nights');
        if ($rowNights !== $expectedNights) {
            return "FAIL: accrual-revenue room nights {$rowNights} vs {$expectedNights}";
        }

        foreach ($report['rows'] ?? [] as $row) {
            $date = (string) ($row['charge_date'] ?? '');
            $items = $detailsByDate->get($date, collect());
            $expectedDayRevenue = round($items->sum('revenue'), 2);
            if (! $this->near((float) ($row['revenue'] ?? 0), $expectedDayRevenue)) {
                return "FAIL: accrual-revenue revenue on {$date}";
            }

            $activeCount = Reservation::countActiveOnDate($date);
            if ((int) ($row['active_bookings'] ?? -1) !== $activeCount) {
                return "FAIL: accrual-revenue active bookings on {$date}";
            }

            if ($activeCount > 0) {
                $expectedGuests = Reservation::excludingCancelled()
                    ->overlappingDate($date)
                    ->with('client')
                    ->get()
                    ->map(fn (Reservation $reservation) => $reservation->guestDisplayName())
                    ->map(fn ($name) => trim((string) $name))
                    ->filter(fn ($name) => $name !== '' && $name !== '—')
                    ->unique()
                    ->sort()
                    ->values()
                    ->implode(', ') ?: '—';

                if ((string) ($row['guest'] ?? '') !== $expectedGuests) {
                    return "FAIL: accrual-revenue guest list on {$date}";
                }
            }
        }

        return 'PASS: accrual-revenue';
    }

    private function verifyRevenueSummary(array $report, Carbon $start, Carbon $end): string
    {
        $accrual = $this->revenueAccrualService->calculate('total', null, $start, $end, true);
        $expectedRevenue = round((float) $accrual['current']['total'], 2);
        $expectedEarnedNights = (int) $accrual['current']['count'];
        $detailsByDate = collect($accrual['details'] ?? [])->groupBy('charge_date');

        if (! $this->near($this->summaryFloat($report, 'Total revenue'), $expectedRevenue)) {
            return 'FAIL: revenue-summary total revenue';
        }

        $rowRevenue = round(collect($report['rows'] ?? [])->sum(fn ($r) => (float) ($r['revenue'] ?? 0)), 2);
        if (! $this->near($rowRevenue, $expectedRevenue)) {
            return "FAIL: revenue-summary row revenue sum {$rowRevenue} vs {$expectedRevenue}";
        }

        $earnedRowTotal = (int) collect($report['rows'] ?? [])->sum('earned_room_nights');
        if ($earnedRowTotal !== $expectedEarnedNights) {
            return "FAIL: revenue-summary earned nights {$earnedRowTotal} vs {$expectedEarnedNights}";
        }

        if ((int) $this->summaryFloat($report, 'Room nights (earned)') !== $expectedEarnedNights) {
            return 'FAIL: revenue-summary earned nights summary';
        }

        $bookedRowTotal = (int) collect($report['rows'] ?? [])->sum('room_nights');
        if ((int) $this->summaryFloat($report, 'Room nights (booked)') !== $bookedRowTotal) {
            return 'FAIL: revenue-summary booked nights summary';
        }

        $expectedDays = (int) ($start->diffInDays($end) + 1);
        if (count($report['rows'] ?? []) !== $expectedDays) {
            return 'FAIL: revenue-summary day row count';
        }

        $recognizedEnd = $end->copy()->startOfDay();
        $today = Carbon::today()->startOfDay();
        if ($recognizedEnd->gt($today)) {
            $recognizedEnd = $today;
        }

        $bookedByDate = ReservationDailyCharge::query()
            ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
            ->where('reservations.reservation_status', 1)
            ->whereBetween('reservation_daily_charges.charge_date', [
                $start->toDateString(),
                $end->toDateString(),
            ])
            ->selectRaw('DATE(reservation_daily_charges.charge_date) as charge_day, COUNT(*) as booked')
            ->groupBy('charge_day')
            ->pluck('booked', 'charge_day');

        foreach ($report['rows'] ?? [] as $row) {
            $date = (string) ($row['charge_date'] ?? '');
            $expectedBooked = (int) ($bookedByDate[$date] ?? 0);
            if ((int) ($row['room_nights'] ?? -1) !== $expectedBooked) {
                return "FAIL: revenue-summary booked nights on {$date}";
            }

            $expectedEarnedDay = $expectedBooked;
            if (Carbon::parse($date)->startOfDay()->gt($today)) {
                $expectedEarnedDay = 0;
            }
            if ((int) ($row['earned_room_nights'] ?? -1) !== $expectedEarnedDay) {
                return "FAIL: revenue-summary earned nights on {$date}";
            }

            $activeCount = Reservation::countActiveOnDate($date);
            if ((int) ($row['active_bookings'] ?? -1) !== $activeCount) {
                return "FAIL: revenue-summary active bookings on {$date}";
            }

            $expectedDayRevenue = round(collect($detailsByDate->get($date, collect()))->sum('revenue'), 2);
            if (! $this->near((float) ($row['revenue'] ?? 0), $expectedDayRevenue)) {
                return "FAIL: revenue-summary revenue on {$date}";
            }
        }

        return 'PASS: revenue-summary';
    }

    private function verifyTax(array $report, Carbon $start, Carbon $end): string
    {
        $expected = round((float) $this->revenueAccrualService->calculate('total', null, $start, $end, false)['current']['tax'], 2);
        if (! $this->near($this->summaryFloat($report, 'Total tax'), $expected)) {
            return 'FAIL: tax total';
        }

        return 'PASS: tax';
    }

    private function verifyRevpar(array $report, Carbon $start, Carbon $end): string
    {
        $accrual = $this->revenueAccrualService->calculate('total', null, $start, $end, false);
        $revenue = round((float) $accrual['current']['total'], 2);
        $subtotal = round((float) $accrual['current']['subtotal'], 2);
        $nights = (int) $accrual['current']['count'];
        $roomCount = Room::query()->where('active', 1)->whereNotIn('roomStatus', [3, 4])->count();
        $days = $start->diffInDays($end) + 1;
        $avail = $roomCount * $days;
        $expectedRevpar = $avail > 0 ? round($revenue / $avail, 2) : 0.0;
        $expectedAdr = $nights > 0 ? round($subtotal / $nights, 2) : 0.0;

        if (! $this->near($this->summaryFloat($report, 'Revenue (incl. tax)'), $revenue)) {
            return 'FAIL: revpar revenue';
        }
        if (! $this->near($this->summaryFloat($report, 'RevPAR (revenue / available room nights)'), $expectedRevpar)) {
            return 'FAIL: revpar value';
        }
        if (! $this->near($this->summaryFloat($report, 'ADR (subtotal / room nights)'), $expectedAdr)) {
            return 'FAIL: ADR';
        }

        return 'PASS: revpar';
    }

    private function verifyOccupancy(array $report, Carbon $start, Carbon $end): string
    {
        $expectedNights = ReservationDailyCharge::query()
            ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
            ->where('reservations.reservation_status', Reservation::STATUS_CONFIRMED)
            ->whereBetween('reservation_daily_charges.charge_date', [
                $start->toDateString(),
                $end->toDateString(),
            ])
            ->count();
        $sold = (int) $this->summaryFloat($report, 'Total room nights sold');

        if ($sold !== $expectedNights) {
            return "FAIL: occupancy sold nights {$sold} vs {$expectedNights}";
        }

        $totalRooms = Room::query()
            ->where('active', 1)
            ->whereNotIn('roomStatus', [3, 4])
            ->count();

        foreach ($report['rows'] ?? [] as $row) {
            $booked = (int) ($row['room_nights'] ?? 0);
            $available = (int) ($row['available_rooms'] ?? 0);
            $expectedAvailable = max(0, $totalRooms - $booked);

            if (! $this->near($available, $expectedAvailable)) {
                return "FAIL: occupancy available rooms {$available} vs {$expectedAvailable} on {$row['date']}";
            }
        }

        return 'PASS: occupancy';
    }

    private function verifyCashReport(array $report, Carbon $start, Carbon $end, string $slug): string
    {
        $cash = $this->cashForPeriod($start, $end);
        if (! $this->near($this->summaryFloat($report, 'Cash in'), round($cash['total_in'], 2))) {
            return "FAIL: {$slug} cash in";
        }
        if (! $this->near($this->summaryFloat($report, 'Net'), round($cash['net_earnings'], 2))) {
            return "FAIL: {$slug} net";
        }

        return "PASS: {$slug}";
    }

    private function verifyReconciliation(array $report, Carbon $start, Carbon $end): string
    {
        $accrual = round((float) $this->revenueAccrualService->calculate('total', null, $start, $end, false)['current']['total'], 2);
        $cashNet = round($this->cashForPeriod($start, $end)['net_earnings'], 2);
        $diff = round($accrual - $cashNet, 2);

        if (! $this->near($this->summaryFloat($report, 'Accrual revenue'), $accrual)) {
            return 'FAIL: reconciliation accrual';
        }
        if (! $this->near($this->summaryFloat($report, 'Cash net'), $cashNet)) {
            return 'FAIL: reconciliation cash net';
        }
        if (! $this->near($this->summaryFloat($report, 'Difference'), $diff)) {
            return 'FAIL: reconciliation difference';
        }

        return 'PASS: accrual-cash-reconciliation';
    }

    private function verifyClosingPackage(array $report, Carbon $start, Carbon $end): string
    {
        $accrual = $this->revenueAccrualService->calculate('total', null, $start, $end, false);
        $cash = $this->cashForPeriod($start, $end);
        $accrualTotal = round((float) $accrual['current']['total'], 2);
        $tax = round((float) $accrual['current']['tax'], 2);
        $cashNet = round($cash['net_earnings'], 2);
        $ar = $this->totalAccountsReceivable($end);

        $rows = collect($report['rows'] ?? [])->keyBy('metric');
        $checks = [
            'Accrual revenue (incl. tax)' => $accrualTotal,
            'Tax (accrual)' => $tax,
            'Cash net' => $cashNet,
            'Accounts receivable (open balances)' => $ar,
            'Accrual − cash net' => round($accrualTotal - $cashNet, 2),
        ];

        foreach ($checks as $metric => $expected) {
            $actual = (float) ($rows->get($metric)['amount'] ?? 0);
            if (! $this->near($actual, $expected)) {
                return "FAIL: closing-package {$metric} {$actual} vs {$expected}";
            }
        }

        return 'PASS: closing-package';
    }

    private function verifyByDimension(array $report, Carbon $start, Carbon $end): string
    {
        $accrual = $this->revenueAccrualService->calculate('total', null, $start, $end, false);
        $expected = round((float) $accrual['current']['total'], 2);
        $rowSum = round(collect($report['rows'] ?? [])->sum(fn ($r) => (float) ($r['revenue'] ?? 0)), 2);

        if (! $this->near($this->summaryFloat($report, 'Total revenue'), $expected) || ! $this->near($rowSum, $expected)) {
            return 'FAIL: by-dimension revenue sum';
        }

        return 'PASS: by-dimension';
    }

    private function verifyPeakAnalysis(array $report, Carbon $start, Carbon $end): string
    {
        $accrual = $this->revenueAccrualService->calculate('total', null, $start, $end, true);
        $expected = round(collect($accrual['details'] ?? [])->sum('revenue'), 2);
        $peak = round(collect($report['rows'] ?? [])->sum(fn ($r) => (float) ($r['revenue'] ?? 0)), 2);

        if (! $this->near($this->summaryFloat($report, 'Total revenue'), $expected) || ! $this->near($peak, $expected)) {
            return 'FAIL: peak-analysis revenue';
        }

        return 'PASS: peak-analysis';
    }

    private function verifyAdjustments(array $report, Carbon $start, Carbon $end): string
    {
        $reservationIds = ReservationDailyCharge::query()
            ->whereBetween('charge_date', [$start->toDateString(), $end->toDateString()])
            ->distinct()
            ->pluck('reservation_id');

        $expected = round((float) Reservation::query()
            ->whereIn('id', $reservationIds)
            ->where('reservation_status', 1)
            ->get()
            ->sum(fn (Reservation $r) => (float) $r->extras + (float) $r->penalties - (float) $r->classification_discount - (float) $r->discount), 2);

        $rowSum = round(collect($report['rows'] ?? [])->sum(fn ($r) => (float) ($r['net_adjustment'] ?? 0)), 2);

        if (! $this->near($rowSum, $expected)) {
            return "FAIL: adjustments {$rowSum} vs {$expected}";
        }

        return 'PASS: adjustments';
    }

    private function verifyArAging(array $report, Carbon $asOf): string
    {
        $expected = $this->totalAccountsReceivable($asOf);
        $reportAr = $this->summaryFloat($report, 'Total AR');
        $bucketSum = round(
            $this->summaryFloat($report, '0-30 days')
            + $this->summaryFloat($report, '31-60 days')
            + $this->summaryFloat($report, '61-90 days')
            + $this->summaryFloat($report, '90+ days'),
            2
        );

        if (! $this->near($reportAr, $expected) || ! $this->near($bucketSum, $expected)) {
            return "FAIL: ar-aging {$reportAr} buckets {$bucketSum} vs {$expected}";
        }

        return 'PASS: ar-aging';
    }

    private function verifyChartOfAccounts(array $report, Carbon $start, Carbon $end): string
    {
        $accrual = $this->revenueAccrualService->calculate('total', null, $start, $end, false);
        $balances = $this->financialStatementService->accountBalances($start, $end)->keyBy('code');
        $row4010 = collect($report['rows'] ?? [])->firstWhere('code', '4010');

        if ($row4010 === null) {
            return 'FAIL: chart-of-accounts missing 4010';
        }

        if (! $this->near((float) $row4010['balance'], round((float) $accrual['current']['subtotal'], 2))) {
            return 'FAIL: chart-of-accounts 4010';
        }
        if (! $this->near(
            (float) (collect($report['rows'] ?? [])->firstWhere('code', '2150')['balance'] ?? 0),
            round((float) $accrual['current']['tax'], 2)
        )) {
            return 'FAIL: chart-of-accounts 2150';
        }
        if (! $this->near(
            (float) ($row4010['balance'] ?? 0),
            round((float) ($balances->get('4010')->balance ?? 0), 2)
        )) {
            return 'FAIL: chart-of-accounts vs service 4010';
        }

        return 'PASS: chart-of-accounts';
    }

    private function verifyTrialBalance(array $report, Carbon $start, Carbon $end): string
    {
        $service = $this->financialStatementService->trialBalance($start, $end);
        $balanced = ($this->reportSummaryString($report, 'Balanced') ?? '') === 'Yes';

        if (! $balanced && $service['totals']['balanced']) {
            return 'FAIL: trial-balance balanced flag';
        }
        if (! $this->near($this->summaryFloat($report, 'Total debit'), (float) $service['totals']['debit'])) {
            return 'FAIL: trial-balance debit';
        }
        if (! $this->near($this->summaryFloat($report, 'Total credit'), (float) $service['totals']['credit'])) {
            return 'FAIL: trial-balance credit';
        }

        return 'PASS: trial-balance';
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function verifyGeneralLedger(array $report, Carbon $start, Carbon $end, array $params): string
    {
        $accountType = (string) ($params['account_type'] ?? 'asset');
        $accountId = ! empty($params['account_id']) ? (int) $params['account_id'] : null;
        $service = $this->financialStatementService->generalLedger($start, $end, $accountId, $accountType);
        $debit = round(collect($report['rows'] ?? [])->sum(fn ($r) => (float) ($r['debit'] ?? 0)), 2);
        $credit = round(collect($report['rows'] ?? [])->sum(fn ($r) => (float) ($r['credit'] ?? 0)), 2);

        if (! $this->near($debit, (float) $service['totals']['debit']) || ! $this->near($credit, (float) $service['totals']['credit'])) {
            return 'FAIL: general-ledger totals';
        }

        return 'PASS: general-ledger';
    }

    private function verifyCashFlow(array $report, Carbon $start, Carbon $end): string
    {
        $service = $this->financialStatementService->cashFlow($start, $end);
        if (! $this->near($this->summaryFloat($report, 'Net change'), (float) $service['totals']['net_change'])) {
            return 'FAIL: cash-flow net change';
        }

        return 'PASS: cash-flow';
    }

    private function verifyJournalEntries(array $report, Carbon $start, Carbon $end): string
    {
        $entryCount = JournalEntry::query()
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->count();
        $lineCount = (int) $this->summaryFloat($report, 'Lines');

        $expectedLines = JournalEntry::with('lines')
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->sum(fn ($e) => $e->lines->count());

        if ((int) $this->summaryFloat($report, 'Journal entries') !== $entryCount) {
            return 'FAIL: journal-entries count';
        }
        if ($lineCount !== $expectedLines) {
            return "FAIL: journal-entries lines {$lineCount} vs {$expectedLines}";
        }

        return 'PASS: journal-entries';
    }

    private function verifyFinancialAuditLog(array $report, Carbon $start, Carbon $end): string
    {
        $expected = count($this->financialAuditService->listForPeriod(
            $start->toDateString(),
            $end->toDateString()
        ));
        $actual = (int) $this->summaryFloat($report, 'Total events');

        if ($actual !== $expected) {
            return "FAIL: financial-audit-log {$actual} vs {$expected}";
        }

        return 'PASS: financial-audit-log';
    }

    private function verifyArrivalsDepartures(array $report, Carbon $start, Carbon $end): string
    {
        $startStr = $start->toDateString();
        $endStr = $end->toDateString();
        $expectedReservations = Reservation::excludingCancelled()
            ->where('start_date', '<=', $endStr)
            ->where('expire_date', '>=', $startStr)
            ->count();

        $expectedMovements = $this->expectedArrivalsDeparturesMovements($start, $end);
        $summaryReservations = (int) $this->summaryFloat($report, 'Reservations');
        $summaryMovements = (int) $this->summaryFloat($report, 'Total movements');

        if ($summaryReservations !== $expectedReservations) {
            return "FAIL: arrivals-departures reservation count {$summaryReservations} vs {$expectedReservations}";
        }

        if ($summaryMovements !== $expectedMovements || count($report['rows'] ?? []) !== $expectedMovements) {
            return 'FAIL: arrivals-departures movement row count';
        }

        return 'PASS: arrivals-departures';
    }

    private function expectedArrivalsDeparturesMovements(Carbon $start, Carbon $end): int
    {
        $startStr = $start->toDateString();
        $endStr = $end->toDateString();

        if ($startStr === $endStr) {
            $day = $startStr;

            return Reservation::excludingCancelled()->whereDate('start_date', $day)->count()
                + Reservation::excludingCancelled()->whereDate('expire_date', $day)->count()
                + Reservation::excludingCancelled()
                    ->where('start_date', '<', $day)
                    ->where('expire_date', '>', $day)
                    ->count();
        }

        $movements = 0;
        Reservation::excludingCancelled()
            ->where('start_date', '<=', $endStr)
            ->where('expire_date', '>=', $startStr)
            ->select(['start_date', 'expire_date'])
            ->orderBy('id')
            ->each(function (Reservation $reservation) use ($startStr, $endStr, &$movements) {
                $hasArrival = $reservation->start_date >= $startStr && $reservation->start_date <= $endStr;
                $hasDeparture = $reservation->expire_date >= $startStr && $reservation->expire_date <= $endStr;
                if ($hasArrival) {
                    $movements++;
                }
                if ($hasDeparture) {
                    $movements++;
                }
                if (! $hasArrival && ! $hasDeparture) {
                    $movements++;
                }
            });

        return $movements;
    }

    private function verifyReservationsList(array $report, Carbon $start, Carbon $end): string
    {
        $expected = Reservation::excludingCancelled()
            ->where('start_date', '<=', $end->toDateString())
            ->where('expire_date', '>=', $start->toDateString())
            ->count();

        if ((int) $this->summaryFloat($report, 'Reservations') !== $expected) {
            return 'FAIL: reservations-list count';
        }
        if (count($report['rows'] ?? []) !== $expected) {
            return 'FAIL: reservations-list rows';
        }

        return 'PASS: reservations-list';
    }

    private function verifyRoomBoard(array $report, Carbon $date): string
    {
        $board = $this->occupancyService->buildBoard($date);
        $expectedTotal = (int) ($board['summary']['total'] ?? 0);
        $expectedInHouse = (int) ($board['summary']['in_house'] ?? 0);

        if ((int) $this->summaryFloat($report, 'Total rooms') !== $expectedTotal) {
            return 'FAIL: room-board total rooms';
        }
        if ((int) $this->summaryFloat($report, 'In house') !== $expectedInHouse) {
            return 'FAIL: room-board in house';
        }
        if (count($report['rows'] ?? []) !== $expectedTotal) {
            return 'FAIL: room-board row count';
        }

        return 'PASS: room-board';
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

        $totalIn = (float) ReservationPay::query()
            ->join('reservations', 'reservation_pay.reservation_id', '=', 'reservations.id')
            ->whereIn('reservations.reservation_status', Reservation::cashMovementStatuses())
            ->whereBetween('reservation_pay.created_at', [$periodStart, $periodEnd])
            ->where('reservation_pay.type', ReservationPay::TYPE_PAYMENT)
            ->sum('reservation_pay.pay');

        $totalOut = (float) ReservationPay::query()
            ->join('reservations', 'reservation_pay.reservation_id', '=', 'reservations.id')
            ->whereIn('reservations.reservation_status', Reservation::cashMovementStatuses())
            ->whereBetween('reservation_pay.created_at', [$periodStart, $periodEnd])
            ->where('reservation_pay.type', ReservationPay::TYPE_REFUND)
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
                $paid = (float) $reservation->payments
                    ->where('type', ReservationPay::TYPE_PAYMENT)
                    ->sum('pay');
                $refunded = (float) $reservation->payments
                    ->where('type', ReservationPay::TYPE_REFUND)
                    ->sum('pay');
                $resTotal = (float) $reservation->total;
                if ($resTotal <= 0 && (float) $reservation->subtotal > 0) {
                    $resTotal = round((float) $reservation->subtotal * (1 + self::TAX_RATE), 2);
                }
                $total += max(0, $resTotal - ($paid - $refunded));
            });

        return round($total, 2);
    }

    private function near(float $actual, float $expected, float $delta = self::DELTA): bool
    {
        return abs($actual - $expected) <= $delta;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function summaryFloat(array $report, string $label): float
    {
        foreach ($report['summary'] ?? [] as $item) {
            if (($item['label'] ?? '') === $label && is_numeric($item['value'])) {
                return (float) $item['value'];
            }
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function reportSummaryString(array $report, string $label): ?string
    {
        foreach ($report['summary'] ?? [] as $item) {
            if (($item['label'] ?? '') === $label) {
                return is_scalar($item['value'] ?? null) ? (string) $item['value'] : null;
            }
        }

        return null;
    }
}
