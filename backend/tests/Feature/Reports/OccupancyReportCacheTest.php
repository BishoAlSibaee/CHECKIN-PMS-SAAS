<?php

namespace Tests\Feature\Reports;

use App\Services\OccupancyBoardCache;
use App\Services\OccupancyReportCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OccupancyReportCacheTest extends TestCase
{
    public function test_occupancy_report_cache_reuses_payload_until_board_bump(): void
    {
        Cache::flush();

        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');
        $calls = 0;

        $resolver = function () use (&$calls) {
            $calls++;

            return ['rows' => [['date' => '2026-08-01', 'room_nights' => 1]]];
        };

        $first = OccupancyReportCache::remember($start, $end, null, null, $resolver);
        $second = OccupancyReportCache::remember($start, $end, null, null, $resolver);

        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);

        OccupancyBoardCache::bump();

        $third = OccupancyReportCache::remember($start, $end, null, null, $resolver);

        $this->assertSame(2, $calls);
        $this->assertSame($first, $third);
    }

    public function test_occupancy_report_cache_key_varies_by_compare_period(): void
    {
        Cache::flush();

        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');
        $calls = 0;

        $resolver = function () use (&$calls) {
            $calls++;

            return ['rows' => []];
        };

        OccupancyReportCache::remember($start, $end, null, null, $resolver);
        OccupancyReportCache::remember(
            $start,
            $end,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
            $resolver
        );

        $this->assertSame(2, $calls);
    }
}
