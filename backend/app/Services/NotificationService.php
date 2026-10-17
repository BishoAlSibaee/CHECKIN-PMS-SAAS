<?php

namespace App\Services;

use App\Models\AppNotification;

/**
 * Creates a persisted staff notification and pushes a live "something
 * changed" signal so the header bell (and its badge count) update without a
 * page reload — the bell itself always re-fetches the actual list from
 * NotificationController rather than trusting the broadcast payload.
 */
class NotificationService
{
    public function __construct(private HotelLivePublisher $livePublisher) {}

    /** @param array<string, mixed> $params */
    public function notify(
        ?int $hotelId,
        string $type,
        string $titleKey,
        array $params,
        ?string $link,
        ?string $requiredPermission
    ): AppNotification {
        $notification = AppNotification::create([
            'hotel_id' => $hotelId,
            'type' => $type,
            'title_key' => $titleKey,
            'params' => $params,
            'link' => $link,
            'required_permission' => $requiredPermission,
        ]);

        $this->livePublisher->publish(
            [HotelLivePublisher::SCOPE_NOTIFICATIONS],
            'notification_created',
            ['type' => 'app_notification', 'id' => $notification->id]
        );

        return $notification;
    }
}
