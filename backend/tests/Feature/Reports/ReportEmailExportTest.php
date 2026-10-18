<?php

namespace Tests\Feature\Reports;

use App\Jobs\ProcessReportExportJob;
use App\Mail\ReportReadyMail;
use App\Models\ReportExport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportEmailExportTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    public function test_user_can_queue_email_export_with_custom_recipient(): void
    {
        Queue::fake();

        $user = $this->userWithApiPermissions(['view reports', 'view financial reports']);

        $response = $this->actingAs($user, 'api')->postJson('/api/users/reports/revenue-summary/email-request', [
            'email' => 'finance@hotel.test',
            'format' => 'excel',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.export.email', 'finance@hotel.test');
        $response->assertJsonPath('data.export.format', 'excel');
        $response->assertJsonPath('data.export.status', ReportExport::STATUS_PENDING);

        $this->assertDatabaseHas('report_exports', [
            'user_id' => $user->id,
            'slug' => 'revenue-summary',
            'recipient_email' => 'finance@hotel.test',
            'status' => ReportExport::STATUS_PENDING,
        ]);

        Queue::assertPushed(ProcessReportExportJob::class);

        ReportExport::query()
            ->where('recipient_email', 'finance@hotel.test')
            ->where('status', ReportExport::STATUS_PENDING)
            ->latest('id')
            ->first()
            ?->delete();
    }

    public function test_user_can_download_full_pdf_directly(): void
    {
        $user = $this->userWithApiPermissions(['view reports', 'view financial reports']);

        $response = $this->actingAs($user, 'api')->get(
            '/api/users/reports/accrual-revenue/download?'
            .http_build_query([
                'format' => 'pdf',
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-30',
            ])
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertGreaterThan(1000, strlen($response->getContent()));
    }

    public function test_process_pending_command_generates_file_and_marks_ready(): void
    {
        Mail::fake();
        Storage::fake('local');

        $user = $this->userWithApiPermissions(['view reports', 'view financial reports']);

        $export = ReportExport::create([
            'user_id' => $user->id,
            'slug' => 'occupancy',
            'file_format' => ReportExport::FORMAT_EXCEL,
            'recipient_email' => 'ops@hotel.test',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-07',
            'status' => ReportExport::STATUS_PENDING,
            'download_token' => ReportExport::generateToken(),
        ]);

        try {
            $this->artisan('reports:process-pending', ['--limit' => 1])
                ->assertExitCode(0);

            $export->refresh();
            $this->assertSame(ReportExport::STATUS_READY, $export->status);
            $this->assertNotNull($export->file_path);
            $this->assertStringEndsWith('.xlsx', $export->file_path);
            Storage::disk('local')->assertExists($export->file_path);

            Mail::assertSent(ReportReadyMail::class, function (ReportReadyMail $mail) use ($export) {
                return $mail->hasTo('ops@hotel.test')
                    && $mail->export->id === $export->id;
            });
        } finally {
            if ($export->file_path) {
                Storage::disk('local')->delete($export->file_path);
            }
            $export->delete();
        }
    }

    public function test_process_pending_command_generates_pdf_file(): void
    {
        Mail::fake();
        Storage::fake('local');

        $user = $this->userWithApiPermissions(['view reports', 'view financial reports']);

        $export = ReportExport::create([
            'user_id' => $user->id,
            'slug' => 'occupancy',
            'file_format' => ReportExport::FORMAT_PDF,
            'recipient_email' => 'ops@hotel.test',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-07',
            'status' => ReportExport::STATUS_PENDING,
            'download_token' => ReportExport::generateToken(),
        ]);

        try {
            $this->artisan('reports:process-pending', ['--limit' => 1])
                ->assertExitCode(0);

            $export->refresh();
            $this->assertSame(ReportExport::STATUS_READY, $export->status);
            $this->assertStringEndsWith('.pdf', $export->file_path);
            Storage::disk('local')->assertExists($export->file_path);
        } finally {
            if ($export->file_path) {
                Storage::disk('local')->delete($export->file_path);
            }
            $export->delete();
        }
    }

    public function test_download_with_valid_token_returns_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('reports/test.xlsx', 'fake-xlsx');

        $user = $this->userWithApiPermissions(['view reports']);
        $token = ReportExport::generateToken();

        $export = ReportExport::create([
            'user_id' => $user->id,
            'slug' => 'occupancy',
            'file_format' => ReportExport::FORMAT_EXCEL,
            'recipient_email' => 'ops@hotel.test',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-07',
            'status' => ReportExport::STATUS_READY,
            'file_path' => 'reports/test.xlsx',
            'download_token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->get('/api/users/reports/exports/'.$export->id.'/download?token='.$token);

        $response->assertOk();
        $response->assertHeader('content-disposition');

        $export->delete();
    }

    public function test_report_response_is_paginated_at_fifty_rows(): void
    {
        config(['reports.page_size' => 50]);

        $user = $this->userWithApiPermissions(['view reports', 'view financial reports']);

        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/reservations-list?start_date=2026-08-01&end_date=2026-08-31&page=1'
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.per_page', 50);
        $response->assertJsonPath('data.current_page', 1);
        $this->assertLessThanOrEqual(50, count($response->json('data.rows') ?? []));
        $this->assertArrayHasKey('total_rows', $response->json('data'));
        $this->assertArrayHasKey('last_page', $response->json('data'));
        $this->assertArrayHasKey('is_truncated', $response->json('data'));
    }

    public function test_for_export_returns_up_to_preview_limit_rows(): void
    {
        config([
            'reports.page_size' => 50,
            'reports.preview_limit' => 500,
            'reports.max_direct_download_rows' => 40,
        ]);

        $user = $this->userWithApiPermissions(['view reports', 'view financial reports']);

        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/reservations-list?start_date=2026-08-01&end_date=2026-08-31&for_export=1'
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.current_page', 1);
        $response->assertJsonPath('data.last_page', 1);
        $this->assertArrayHasKey('export_limit', $response->json('data'));
        $totalRows = (int) $response->json('data.total_rows');
        $rowCount = count($response->json('data.rows') ?? []);
        $this->assertSame(min($totalRows, 40), $rowCount);
    }

    public function test_run_report_includes_direct_download_policy_flags(): void
    {
        config(['reports.max_direct_download_rows' => 40]);

        $user = $this->userWithApiPermissions(['view reports', 'view financial reports']);

        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/reservations-list?start_date=2026-08-01&end_date=2026-08-31&page=1'
        );

        $response->assertOk();
        $response->assertJsonPath('data.max_direct_download_rows', 40);
        $this->assertArrayHasKey('direct_download_allowed', $response->json('data'));
        $this->assertArrayHasKey('requires_email_or_filters', $response->json('data'));
    }

    public function test_direct_download_rejects_when_over_row_limit(): void
    {
        config(['reports.max_direct_download_rows' => 2]);

        $this->mock(\App\Services\Reports\ReportQueryService::class, function ($mock) {
            $mock->shouldReceive('run')->once()->andReturn([
                'columns' => [['key' => 'id', 'label' => 'ID']],
                'rows' => [
                    ['id' => 1],
                    ['id' => 2],
                    ['id' => 3],
                ],
                'summary' => [],
            ]);
        });

        $user = $this->userWithApiPermissions(['view reports', 'view financial reports']);

        $response = $this->actingAs($user, 'api')->get(
            '/api/users/reports/reservations-list/download?'
            .http_build_query([
                'format' => 'excel',
                'start_date' => '2026-07-01',
                'end_date' => '2026-07-31',
            ])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('direct download limit', (string) $response->json('message'));
    }
}
