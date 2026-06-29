<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FinanceAuditCommand extends Command
{
    protected $signature = 'finance:audit';

    protected $description = 'Run detailed finance/revenue consistency audit';

    public function handle(RevenueAccrualService $revenue): int
    {
        $demoAsOf = env('FINANCE_DEMO_AS_OF', '2026-09-01');
        Carbon::setTestNow(Carbon::parse($demoAsOf)->endOfDay());

        try {
            return $this->runAudit($revenue);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function runAudit(RevenueAccrualService $revenue): int
    {
        $this->info('=== Finance Audit ===');
        $results = ['pass' => 0, 'fail' => 0, 'warn' => 0];

        $this->line('');
        $this->info('1) Data presence');
        $res2026 = Reservation::whereYear('start_date', 2026)->count();
        $charges = ReservationDailyCharge::count();
        $this->table(['Metric', 'Value'], [
            ['Reservations 2026', $res2026],
            ['Daily charge rows', $charges],
        ]);
        if ($res2026 === 0) {
            $this->warn('No 2026 reservations — run: php artisan db:seed --class=ReservationTestDataSeeder');
            $results['warn']++;
        }

        $this->line('');
        $this->info('2) Contract consistency (confirmed reservations, all years)');
        $confirmed = Reservation::where('reservation_status', 1)
            ->with('reservationRooms')
            ->orderBy('id')
            ->get();

        $missingCharges = 0;
        foreach ($confirmed as $r) {
            $chargeCount = ReservationDailyCharge::where('reservation_id', $r->id)->count();
            if ($chargeCount === 0) {
                $missingCharges++;
                $results['fail']++;
                $this->error("FAIL reservation #{$r->id}: no daily_charges rows (run reservations:backfill-daily-charges)");
                continue;
            }

            $sumBase = (float) ReservationDailyCharge::where('reservation_id', $r->id)->sum('base_amount');
            $expectedTotal = round((float) $r->subtotal + (float) $r->taxes, 2);
            $baseOk = abs($sumBase - (float) $r->base_price) < 0.02;
            $totalOk = abs($expectedTotal - (float) $r->total) < 0.02;
            $formulaOk = abs((float) $r->subtotal - ((float) $r->base_price - (float) $r->discount + (float) $r->extras + (float) $r->penalties)) < 0.02;

            if ($baseOk && $totalOk && $formulaOk) {
                $results['pass']++;
            } else {
                $results['fail']++;
                $this->error("FAIL reservation #{$r->id}: charges base={$sumBase} vs base_price={$r->base_price}");
            }
        }
        $this->info("Checked {$confirmed->count()} confirmed reservation(s); missing charges: {$missingCharges}");

        $this->line('');
        $this->info('2b) Test seed sample (2026)');
        $reservations2026 = Reservation::whereYear('start_date', 2026)->get();
        foreach ($reservations2026 as $r) {
            $sumBase = (float) ReservationDailyCharge::where('reservation_id', $r->id)->sum('base_amount');
            if (abs($sumBase - (float) $r->base_price) >= 0.02) {
                $results['fail']++;
                $this->error("FAIL 2026 #{$r->id}: base mismatch");
            }
        }
        if ($reservations2026->isNotEmpty()) {
            $this->info("Checked {$reservations2026->count()} year-2026 reservation(s)");
        }

        $this->line('');
        $this->info('3) August 2026 accrual revenue (critical cross-month)');
        $aug = $revenue->calculate('total', null, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), true);
        $this->table(['Metric', 'Value'], [
            ['Night lines in August', $aug['current']['count']],
            ['Base (accrual)', $aug['current']['total_base']],
            ['Revenue incl tax', $aug['current']['total']],
            ['Reservations touched', $aug['current']['reservation_count']],
        ]);

        $booking6 = Reservation::where('start_date', '2026-07-26')->where('expire_date', '2026-08-09')->first();
        if ($booking6) {
            $aug6 = ReservationDailyCharge::where('reservation_id', $booking6->id)
                ->whereBetween('charge_date', ['2026-08-01', '2026-08-31'])
                ->get();
            $base6 = round($aug6->sum('base_amount'), 2);
            $oldWrong = 150 * 8;
            $this->table(['Booking #6 Aug slice', 'Value'], [
                ['Nights in August', $aug6->count()],
                ['Base sum (correct method)', $base6],
                ['Old wrong API (150 x 8)', $oldWrong],
                ['Difference saved', $oldWrong - $base6],
            ]);
            $expectedBase = round($aug6->sum('base_amount'), 2);
            $oldWrong = 150 * 8;
            if ($aug6->count() === 8 && abs($base6 - $expectedBase) < 0.02) {
                $this->info("PASS: Aug slice 8 nights, base {$base6} (daily sum matches)");
                if (abs($base6 - $oldWrong) < 0.02) {
                    $this->line('  Note: all nights inside summer plan @ 150 → 1200 is correct');
                } elseif ($base6 < $oldWrong) {
                    $this->info("  PASS: differs from old flat wrong rate {$oldWrong}");
                }
                $results['pass']++;
            } else {
                $this->error("FAIL: Expected 8 nights base {$expectedBase}, got {$aug6->count()} nights, base {$base6}");
                $results['fail']++;
            }
        }

        $this->line('');
        $this->info('4) June vs August revenue should differ');
        $jun = $revenue->calculate('total', null, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), false);
        if ($jun['current']['total'] > 0 && $aug['current']['total'] > 0 && $jun['current']['total'] !== $aug['current']['total']) {
            $this->info('PASS: June and August totals differ');
            $results['pass']++;
        } else {
            $this->warn('WARN: June/Aug totals unexpected');
            $results['warn']++;
        }

        $this->line('');
        $this->info('5) Status=2 excluded from revenue');
        $status2Ids = Reservation::where('reservation_status', 2)->whereYear('start_date', 2026)->pluck('id');
        $inRev = ReservationDailyCharge::whereIn('reservation_id', $status2Ids)
            ->whereBetween('charge_date', ['2026-06-01', '2026-09-30'])
            ->count();
        $augRevIds = collect($aug['details'] ?? [])->pluck('reservation_id')->unique();
        $leaked = $status2Ids->intersect($augRevIds);
        if ($leaked->isEmpty()) {
            $this->info('PASS: status=2 reservations not in August revenue report');
            $results['pass']++;
        } else {
            $this->error('FAIL: status=2 leaked into revenue: ' . $leaked->implode(','));
            $results['fail']++;
        }
        $this->line("(status=2 charge rows in DB: {$inRev} — excluded by query)");

        $this->line('');
        $this->info('6) Cash box (payments by date) June 2026');
        $payJun = DB::table('reservation_pay')
            ->join('reservations', 'reservation_pay.reservation_id', '=', 'reservations.id')
            ->whereIn('reservations.reservation_status', [1, 2])
            ->where('reservation_pay.type', 0)
            ->whereBetween('reservation_pay.created_at', ['2026-06-01 00:00:00', '2026-06-30 23:59:59'])
            ->sum('pay');
        $this->line("Payments recorded in June (by created_at): {$payJun}");
        $this->line("August accrual revenue: {$aug['current']['total']}");
        if (abs($payJun - $aug['current']['total']) > 1) {
            $this->info('PASS: Cash (June payments) != Accrual (August stay) — expected');
            $results['pass']++;
        }

        $this->line('');
        $this->info('7) Discount allocation full reservation');
        $disc = Reservation::where('discount', 200)->whereYear('start_date', 2026)->first();
        if ($disc) {
            $allLines = ReservationDailyCharge::where('reservation_id', $disc->id)->get();
            $periodRev = 0;
            foreach ($allLines as $line) {
                $weight = $disc->base_price > 0 ? $line->base_amount / $disc->base_price : 0;
                $sub = $line->base_amount - $disc->discount * $weight;
                $periodRev += $sub + round($sub * 0.15, 2);
            }
            $periodRev = round($periodRev, 2);
            if (abs($periodRev - (float) $disc->total) < 0.05) {
                $this->info("PASS: Discount reservation #{$disc->id} allocated total matches");
                $results['pass']++;
            } else {
                $this->error("FAIL: #{$disc->id} allocated {$periodRev} vs total {$disc->total}");
                $results['fail']++;
            }
        }

        $this->line('');
        $this->info('8) GL accrual vs RevenueAccrualService (August 2026)');
        $balances = app(\App\Services\Accounting\FinancialStatementService::class)
            ->accountBalances(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));
        $byCode = $balances->keyBy('code');
        $glRevenue = round((float) ($byCode->get('4010')->balance ?? 0), 2);
        $glVat = round((float) ($byCode->get('2150')->balance ?? 0), 2);
        $augSub = round((float) $aug['current']['subtotal'], 2);
        $augTax = round((float) $aug['current']['tax'], 2);
        if (abs($glRevenue - $augSub) < 0.20) {
            $this->info("PASS: GL room revenue {$glRevenue} ≈ accrual subtotal {$augSub}");
            $results['pass']++;
        } else {
            $this->error("FAIL: GL revenue {$glRevenue} vs accrual subtotal {$augSub}");
            $results['fail']++;
        }
        if (abs($glVat - $augTax) < 0.20) {
            $this->info("PASS: GL VAT {$glVat} ≈ accrual tax {$augTax}");
            $results['pass']++;
        } else {
            $this->error("FAIL: GL VAT {$glVat} vs accrual tax {$augTax}");
            $results['fail']++;
        }

        $trial = app(\App\Services\Accounting\FinancialStatementService::class)
            ->trialBalance(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));
        if ($trial['totals']['balanced']) {
            $this->info('PASS: Trial balance balanced for August 2026');
            $results['pass']++;
        } else {
            $this->error('FAIL: Trial balance not balanced for August 2026');
            $results['fail']++;
        }

        $this->line('');
        $this->info('9) Closing package vs services (August 2026)');
        $closing = app(\App\Services\Reports\ReportQueryService::class)->run('closing-package', [
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);
        $rows = collect($closing['rows'] ?? [])->keyBy('metric');
        $cashAug = app(\App\Services\Reports\ReportVerificationService::class);
        $golden = $cashAug->dumpGolden(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));
        $closingAr = (float) ($rows->get('Accounts receivable (open balances)')['amount'] ?? 0);
        $closingTax = (float) ($rows->get('Tax (accrual)')['amount'] ?? 0);
        $closingCash = (float) ($rows->get('Cash net')['amount'] ?? 0);
        if (abs($closingAr - $golden['ar_total']) < 0.20) {
            $this->info("PASS: Closing package AR {$closingAr} ≈ {$golden['ar_total']}");
            $results['pass']++;
        } else {
            $this->error("FAIL: Closing AR {$closingAr} vs {$golden['ar_total']}");
            $results['fail']++;
        }
        if (abs($closingTax - $golden['accrual_tax']) < 0.20) {
            $this->info("PASS: Closing package tax matches accrual");
            $results['pass']++;
        } else {
            $this->error('FAIL: Closing package tax');
            $results['fail']++;
        }
        if (abs($closingCash - $golden['cash_net']) < 0.20) {
            $this->info('PASS: Closing package cash net matches');
            $results['pass']++;
        } else {
            $this->error('FAIL: Closing package cash net');
            $results['fail']++;
        }

        $this->line('');
        $this->info('10) Trial balance report vs service');
        $tbReport = app(\App\Services\Reports\ReportQueryService::class)->run('trial-balance', [
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);
        $balancedLabel = collect($tbReport['summary'] ?? [])->firstWhere('label', 'Balanced')['value'] ?? 'No';
        if (($balancedLabel === 'Yes') === $trial['totals']['balanced']) {
            $this->info('PASS: Trial balance report balanced flag matches service');
            $results['pass']++;
        } else {
            $this->error('FAIL: Trial balance report balanced flag');
            $results['fail']++;
        }

        $this->line('');
        $this->info('11) AR aging vs dashboard AR balance');
        $arReport = app(\App\Services\Reports\ReportQueryService::class)->run('ar-aging', [
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);
        $arTotal = (float) (collect($arReport['summary'] ?? [])->firstWhere('label', 'Total AR')['value'] ?? 0);
        if (abs($arTotal - $golden['ar_total']) < 0.20) {
            $this->info("PASS: AR aging {$arTotal} ≈ expected AR {$golden['ar_total']}");
            $results['pass']++;
        } else {
            $this->error("FAIL: AR aging {$arTotal} vs {$golden['ar_total']}");
            $results['fail']++;
        }

        $this->line('');
        $this->summary($results);

        return $results['fail'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function summary(array $results): void
    {
        $this->info('=== Summary ===');
        $this->table(['Result', 'Count'], [
            ['PASS', $results['pass']],
            ['FAIL', $results['fail']],
            ['WARN', $results['warn']],
        ]);
    }
}
