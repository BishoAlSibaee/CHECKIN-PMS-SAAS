<?php

namespace Tests\Feature\Broadcast;

use App\Events\HotelLiveUpdated;
use App\Services\HotelLivePublisher;
use App\Services\OccupancyBoardCache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OccupancyBoardCacheBumpBroadcastTest extends TestCase
{
    public function test_bump_increments_version_and_broadcasts(): void
    {
        Event::fake([HotelLiveUpdated::class]);

        $before = OccupancyBoardCache::version();
        OccupancyBoardCache::bump();
        $after = OccupancyBoardCache::version();

        $this->assertGreaterThan($before, $after);

        Event::assertDispatched(HotelLiveUpdated::class, function (HotelLiveUpdated $event) use ($after) {
            return $event->boardVersion === $after
                && in_array(HotelLivePublisher::SCOPE_OCCUPANCY_BOARD, $event->scopes, true)
                && $event->action === 'board_changed';
        });
    }
}
