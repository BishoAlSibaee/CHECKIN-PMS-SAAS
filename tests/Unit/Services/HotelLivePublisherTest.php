<?php

namespace Tests\Unit\Services;

use App\Events\HotelLiveUpdated;
use App\Services\HotelLivePublisher;
use App\Services\OccupancyBoardCache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class HotelLivePublisherTest extends TestCase
{
    public function test_publish_dispatches_event_with_scopes_and_version(): void
    {
        Event::fake([HotelLiveUpdated::class]);

        $publisher = app(HotelLivePublisher::class);
        $publisher->publish(
            [HotelLivePublisher::SCOPE_OCCUPANCY_BOARD, HotelLivePublisher::SCOPE_DASHBOARD],
            'board_changed',
            ['type' => 'reservation', 'id' => 99]
        );

        Event::assertDispatched(HotelLiveUpdated::class, function (HotelLiveUpdated $event) {
            return $event->boardVersion === OccupancyBoardCache::version()
                && $event->scopes === [HotelLivePublisher::SCOPE_OCCUPANCY_BOARD, HotelLivePublisher::SCOPE_DASHBOARD]
                && $event->action === 'board_changed'
                && $event->entity === ['type' => 'reservation', 'id' => 99]
                && $event->occurredAt !== null;
        });
    }

    public function test_publish_does_nothing_when_broadcast_driver_is_null(): void
    {
        Config::set('broadcasting.default', 'null');

        Event::fake([HotelLiveUpdated::class]);

        app(HotelLivePublisher::class)->publish(
            [HotelLivePublisher::SCOPE_DASHBOARD],
            'board_changed'
        );

        Event::assertNotDispatched(HotelLiveUpdated::class);
    }

    public function test_publish_board_changed_includes_default_scopes(): void
    {
        Event::fake([HotelLiveUpdated::class]);

        app(HotelLivePublisher::class)->publishBoardChanged();

        Event::assertDispatched(HotelLiveUpdated::class, function (HotelLiveUpdated $event) {
            return $event->action === 'board_changed'
                && in_array(HotelLivePublisher::SCOPE_OCCUPANCY_BOARD, $event->scopes, true)
                && in_array(HotelLivePublisher::SCOPE_DASHBOARD, $event->scopes, true)
                && in_array(HotelLivePublisher::SCOPE_PROPERTY, $event->scopes, true);
        });
    }

    public function test_publish_payment_changed_includes_collections_scope(): void
    {
        Event::fake([HotelLiveUpdated::class]);

        app(HotelLivePublisher::class)->publishPaymentChanged(42);

        Event::assertDispatched(HotelLiveUpdated::class, function (HotelLiveUpdated $event) {
            return in_array(HotelLivePublisher::SCOPE_COLLECTIONS, $event->scopes, true)
                && $event->entity === ['type' => 'reservation', 'id' => 42]
                && $event->action === 'payment_recorded';
        });
    }
}
