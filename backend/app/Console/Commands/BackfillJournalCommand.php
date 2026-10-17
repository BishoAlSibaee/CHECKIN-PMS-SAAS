<?php

namespace App\Console\Commands;

use App\Models\JournalEntry;
use App\Models\ReservationPay;
use App\Services\Accounting\AccountingPostingService;
use Illuminate\Console\Command;

class BackfillJournalCommand extends Command
{
    protected $signature = 'accounting:backfill-journal
                            {--dry-run : List entries without posting}
                            {--payments-only : Skip accrual backfill}
                            {--accrual-only : Skip payment backfill}';

    protected $description = 'Backfill journal entries from payments/refunds and daily charge accruals';

    public function handle(AccountingPostingService $postingService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $paymentsOnly = (bool) $this->option('payments-only');
        $accrualOnly = (bool) $this->option('accrual-only');

        $payCount = 0;
        $paySkipped = 0;
        $accrualCount = 0;

        if (!$accrualOnly) {
            ReservationPay::orderBy('id')->chunk(100, function ($payments) use ($postingService, $dryRun, &$payCount, &$paySkipped) {
                foreach ($payments as $payment) {
                    if ($dryRun) {
                        $ref = 'PAY-' . $payment->id;
                        if (JournalEntry::where('reference', $ref)->exists()) {
                            $paySkipped++;
                        } else {
                            $payCount++;
                            $this->line("Would post payment #{$payment->id} ({$payment->pay})");
                        }
                        continue;
                    }

                    $entry = $postingService->postPayment($payment);
                    if ($entry) {
                        $payCount++;
                    } else {
                        $paySkipped++;
                    }
                }
            });

            $this->info($dryRun
                ? "Payments dry run: {$payCount} would post, {$paySkipped} skipped."
                : "Payments: posted {$payCount}, skipped {$paySkipped}.");
        }

        if (!$paymentsOnly) {
            if ($dryRun) {
                $would = \App\Models\ReservationDailyCharge::query()
                    ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
                    ->where('reservations.reservation_status', 1)
                    ->count();
                $this->info("Accrual dry run: {$would} charge rows eligible.");
            } else {
                $accrualCount = $postingService->backfillAllAccruals();
                $this->info("Accrual: posted {$accrualCount} journal entries.");
            }
        }

        return self::SUCCESS;
    }
}
