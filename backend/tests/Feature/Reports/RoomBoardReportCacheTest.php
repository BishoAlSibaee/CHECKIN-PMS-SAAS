<?php

namespace Tests\Feature\Reports;

use App\Events\HotelLiveUpdated;
use App\Services\OccupancyBoardCache;
use App\Services\Reports\ReportQueryService;
use App\Services\RoomOccupancyService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class RoomBoardReportCacheTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_room_board_report_reuses_occupancy_board_cache(): void
    {
        $date = Carbon::parse('2026-08-10');
        $boardPayload = [
            'date' => $date->toDateString(),
            'summary' => ['total' => 2, 'in_house' => 1, 'occupancy_rate' => 50],
            'rooms' => [
                [
                    'number' => '101',
                    'building_name' => 'Main',
                    'floor_name' => '1',
                    'room_type_name' => 'Standard',
                    'occupancy_status' => 'in_house',
                    'operational_status_label' => 'Available',
                    'reservation' => [
                        'client_name' => 'Guest A',
                        'reservation_id' => 1,
                        'start_date' => '2026-08-09',
                        'expire_date' => '2026-08-11',
                    ],
                ],
            ],
        ];

        $mock = Mockery::mock(RoomOccupancyService::class);
        $mock->shouldReceive('buildBoard')
            ->once()
            ->with(Mockery::type(Carbon::class))
            ->andReturn($boardPayload);

        $this->app->instance(RoomOccupancyService::class, $mock);

        $service = app(ReportQueryService::class);

        $first = $service->run('room-board', ['end_date' => $date->toDateString()]);
        $second = $service->run('room-board', ['end_date' => $date->toDateString()]);

        $this->assertSame($first['rows'], $second['rows']);
        $this->assertCount(1, $first['rows']);
    }

    public function test_room_board_report_cache_invalidates_after_board_bump(): void
    {
        $date = Carbon::parse('2026-08-11');
        $calls = 0;

        $mock = Mockery::mock(RoomOccupancyService::class);
        $mock->shouldReceive('buildBoard')
            ->twice()
            ->andReturnUsing(function () use (&$calls, $date) {
                $calls++;

                return [
                    'date' => $date->toDateString(),
                    'summary' => ['total' => $calls, 'in_house' => 0, 'occupancy_rate' => 0],
                    'rooms' => [],
                ];
            });

        $this->app->instance(RoomOccupancyService::class, $mock);

        $service = app(ReportQueryService::class);
        $service->run('room-board', ['end_date' => $date->toDateString()]);

        Event::fake([HotelLiveUpdated::class]);
        OccupancyBoardCache::bump();

        $afterBump = $service->run('room-board', ['end_date' => $date->toDateString()]);

        $this->assertSame(2, $calls);
        $this->assertSame(2, $afterBump['summary'][1]['value']);
    }
}
