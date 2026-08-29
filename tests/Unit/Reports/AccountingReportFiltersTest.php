<?php

namespace Tests\Unit\Reports;

use App\Services\Reports\AccountingReportFilters;
use PHPUnit\Framework\TestCase;

class AccountingReportFiltersTest extends TestCase
{
    public function test_filters_rows_by_account_type_search_and_hide_zero(): void
    {
        $filters = AccountingReportFilters::from([
            'account_type' => 'expense',
            'search' => 'refund',
            'hide_zero' => true,
        ]);

        $rows = [
            ['code' => '5010', 'name' => 'Discounts', 'type' => 'expense', 'balance' => 0],
            ['code' => '5020', 'name' => 'Refunds', 'type' => 'expense', 'balance' => 966],
            ['code' => '4010', 'name' => 'Room Revenue', 'type' => 'revenue', 'balance' => 5000],
        ];

        $filtered = $filters->applyToRows($rows, [
            'search_keys' => ['code', 'name', 'type'],
            'zero_keys' => ['balance'],
            'type_key' => 'type',
        ]);

        $this->assertCount(1, $filtered);
        $this->assertSame('5020', $filtered[0]['code']);
    }

    public function test_amount_range_matches_any_amount_column(): void
    {
        $filters = AccountingReportFilters::from([
            'min_amount' => 100,
            'max_amount' => 200,
        ]);

        $rows = [
            ['debit' => 0, 'credit' => 50],
            ['debit' => 150, 'credit' => 0],
            ['debit' => 0, 'credit' => 300],
        ];

        $filtered = $filters->applyToRows($rows, [
            'amount_keys' => ['debit', 'credit'],
        ]);

        $this->assertCount(1, $filtered);
        $this->assertEquals(150.0, $filtered[0]['debit']);
    }
}
