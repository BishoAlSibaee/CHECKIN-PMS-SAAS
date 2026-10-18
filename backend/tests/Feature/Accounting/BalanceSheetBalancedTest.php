<?php

namespace Tests\Feature\Accounting;

use App\Services\Accounting\FinancialStatementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BalanceSheetBalancedTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    public function test_balance_sheet_is_balanced_after_accrual_backfill(): void
    {
        Artisan::call('accounting:backfill-journal');

        $asOf = Carbon::parse('2026-08-31');
        $sheet = app(FinancialStatementService::class)->balanceSheet($asOf);

        $this->assertTrue(
            $sheet['totals']['balanced'],
            'Assets '.$sheet['totals']['assets'].' vs L+E '.$sheet['totals']['liabilities_and_equity']
        );
    }
}
