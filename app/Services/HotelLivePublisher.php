<?php

namespace App\Services;

use App\Events\HotelLiveUpdated;
use Illuminate\Support\Facades\Event;

class HotelLivePublisher
{
    public const SCOPE_OCCUPANCY_BOARD = 'occupancy_board';

    public const SCOPE_DASHBOARD = 'dashboard';

    public const SCOPE_RESERVATIONS = 'reservations';

    public const SCOPE_RESERVATION_DETAIL = 'reservation_detail';

    public const SCOPE_COLLECTIONS = 'collections';

    public const SCOPE_PROPERTY = 'property';

    /**
     * @param  list<string>  $scopes
     * @param  array{type: string, id: int|string}|null  $entity
     */
    public function publish(array $scopes, string $action, ?array $entity = null): void
    {
        if (config('broadcasting.default') === 'null') {
            return;
        }

        $scopes = array_values(array_unique($scopes));
        if ($scopes === []) {
            return;
        }

        Event::dispatch(new HotelLiveUpdated(
            boardVersion: OccupancyBoardCache::version(),
            scopes: $scopes,
            action: $action,
            entity: $entity,
        ));
    }

    public function publishBoardChanged(?array $entity = null, string $action = 'board_changed'): void
    {
        $this->publish([
            self::SCOPE_OCCUPANCY_BOARD,
            self::SCOPE_DASHBOARD,
            self::SCOPE_PROPERTY,
        ], $action, $entity);
    }

    public function publishReservationChanged(int $reservationId, string $action, array $extraScopes = []): void
    {
        $this->publish(array_merge([
            self::SCOPE_OCCUPANCY_BOARD,
            self::SCOPE_DASHBOARD,
            self::SCOPE_RESERVATIONS,
            self::SCOPE_RESERVATION_DETAIL,
            self::SCOPE_PROPERTY,
        ], $extraScopes), $action, [
            'type' => 'reservation',
            'id'   => $reservationId,
        ]);
    }

    public function publishPaymentChanged(int $reservationId, string $action = 'payment_recorded'): void
    {
        $this->publish([
            self::SCOPE_COLLECTIONS,
            self::SCOPE_OCCUPANCY_BOARD,
            self::SCOPE_DASHBOARD,
            self::SCOPE_RESERVATION_DETAIL,
            self::SCOPE_RESERVATIONS,
        ], $action, [
            'type' => 'reservation',
            'id'   => $reservationId,
        ]);
    }
}
