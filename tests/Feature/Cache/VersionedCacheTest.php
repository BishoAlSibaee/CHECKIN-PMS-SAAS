<?php

namespace Tests\Feature\Cache;

use App\Jobs\ProcessReportExportJob;
use App\Models\ReportExport;
use App\Services\Cache\DashboardSummaryCache;
use App\Services\Cache\VersionedCache;
use App\Services\OccupancyBoardCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VersionedCacheTest extends TestCase
{
    public function test_versioned_cache_reuses_payload_until_version_changes(): void
    {
        Cache::flush();

        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return ['count' => $calls];
        };

        $first = VersionedCache::remember('demo', 1, 'suffix', 60, $resolver);
        $second = VersionedCache::remember('demo', 1, 'suffix', 60, $resolver);

        $this->assertSame(['count' => 1], $first);
        $this->assertSame(['count' => 1], $second);

        VersionedCache::remember('demo', 2, 'suffix', 60, $resolver);

        $this->assertSame(2, $calls);
    }

    public function test_dashboard_summary_cache_uses_board_version(): void
    {
        Cache::flush();

        $calls = 0;
        $today = now()->startOfDay();

        DashboardSummaryCache::remember($today, function () use (&$calls) {
            $calls++;

            return ['ok' => true];
        });
        DashboardSummaryCache::remember($today, function () use (&$calls) {
            $calls++;

            return ['ok' => true];
        });

        $this->assertSame(1, $calls);

        OccupancyBoardCache::bump();

        DashboardSummaryCache::remember($today, function () use (&$calls) {
            $calls++;

            return ['ok' => true];
        });

        $this->assertSame(2, $calls);
    }

    public function test_report_export_job_is_dispatchable(): void
    {
        Queue::fake();

        $export = ReportExport::create([
            'user_id' => 1,
            'slug' => 'occupancy',
            'file_format' => ReportExport::FORMAT_EXCEL,
            'recipient_email' => 'test@example.com',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => ReportExport::STATUS_PENDING,
            'download_token' => ReportExport::generateToken(),
        ]);

        ProcessReportExportJob::dispatch($export->id);

        Queue::assertPushed(ProcessReportExportJob::class, function (ProcessReportExportJob $job) use ($export) {
            return $job->exportId === $export->id;
        });

        $export->delete();
    }
}
