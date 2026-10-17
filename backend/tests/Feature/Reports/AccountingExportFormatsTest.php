<?php

namespace Tests\Feature\Reports;

use Tests\TestCase;

/**
 * Named accounting-software export profiles (Qoyod, Daftra, generic) — same
 * underlying journal entry data as the existing "journal-entries" report,
 * reshaped into the exact column layout each target expects, so an
 * accountant can import it with zero manual reformatting.
 */
class AccountingExportFormatsTest extends TestCase
{
    public function test_qoyod_export_downloads_as_excel(): void
    {
        $user = $this->userWithApiPermissions(['view reports', 'view accounting reports']);

        $response = $this->actingAs($user, 'api')->get(
            '/api/users/reports/accounting-export-qoyod/download?'
            .http_build_query(['format' => 'excel', 'start_date' => '2000-01-01', 'end_date' => '2000-01-31'])
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $response->getContent());
    }

    public function test_daftra_export_downloads_as_excel(): void
    {
        $user = $this->userWithApiPermissions(['view reports', 'view accounting reports']);

        $response = $this->actingAs($user, 'api')->get(
            '/api/users/reports/accounting-export-daftra/download?'
            .http_build_query(['format' => 'excel', 'start_date' => '2000-01-01', 'end_date' => '2000-01-31'])
        );

        $response->assertOk();
        $this->assertStringStartsWith('PK', $response->getContent());
    }

    public function test_generic_export_downloads_as_excel(): void
    {
        $user = $this->userWithApiPermissions(['view reports', 'view accounting reports']);

        $response = $this->actingAs($user, 'api')->get(
            '/api/users/reports/accounting-export-generic/download?'
            .http_build_query(['format' => 'excel', 'start_date' => '2000-01-01', 'end_date' => '2000-01-31'])
        );

        $response->assertOk();
        $this->assertStringStartsWith('PK', $response->getContent());
    }

    public function test_export_is_forbidden_without_accounting_permission(): void
    {
        $user = $this->userWithApiPermissions(['view reports']);

        $response = $this->actingAs($user, 'api')->get(
            '/api/users/reports/accounting-export-qoyod/download?'
            .http_build_query(['format' => 'excel', 'start_date' => '2000-01-01', 'end_date' => '2000-01-31'])
        );

        $response->assertStatus(403);
    }

    public function test_qoyod_rows_keep_debit_equal_to_credit_and_use_fixed_account_type(): void
    {
        // Full result set, bypassing the HTTP preview endpoint's pagination
        // (which would otherwise risk slicing a multi-line entry in half).
        $service = app(\App\Services\Reports\ReportQueryService::class);
        $data = $service->run('accounting-export-qoyod', [
            'start_date' => '2000-01-01',
            'end_date' => '2100-12-31',
        ]);

        $rows = collect($data['rows']);
        $nonBlankRows = $rows->filter(fn ($r) => ($r['account_code'] ?? '') !== '');
        $this->assertGreaterThan(0, $nonBlankRows->count(), 'Expected at least one existing journal entry line to assert against.');

        $totalDebit = round((float) $nonBlankRows->sum(fn ($r) => (float) ($r['debit'] ?: 0)), 2);
        $totalCredit = round((float) $nonBlankRows->sum(fn ($r) => (float) ($r['credit'] ?: 0)), 2);
        $this->assertEqualsWithDelta($totalDebit, $totalCredit, 0.02);

        $accountTypes = $nonBlankRows->pluck('account_type')->unique()->values()->all();
        $this->assertSame(['G/L Accounts'], $accountTypes);
    }
}
