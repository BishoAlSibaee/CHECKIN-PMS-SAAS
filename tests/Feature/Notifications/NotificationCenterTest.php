<?php

namespace Tests\Feature\Notifications;

use App\Models\AppNotification;
use App\Models\Hotel;
use App\Models\User;
use App\Services\NotificationService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for the general-purpose header-bell notification
 * center (Airbnb requests, new OTA bookings, pricing changes) — the whole
 * point is that a receptionist and an accountant looking at the same bell
 * see genuinely different lists, filtered server-side by hotel and by
 * whichever permission each notification's event actually requires.
 */
class NotificationCenterTest extends TestCase
{
    private function seedHotelAndStaff(array $permissions): array
    {
        $marker = 'Notif Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($hotel->id);

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'api');
        }
        $role = Role::create(['name' => 'notif-test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->syncPermissions($permissions);

        $user = User::create([
            'name' => 'Notif Staff '.uniqid(),
            'job_number' => 'NOTIF-'.uniqid(),
            'email' => 'notif.'.uniqid().'@example.com',
            'mobile' => '05'.substr(uniqid(), -8),
            'active' => 1,
            'password' => bcrypt('test-password'),
        ]);
        $user->hotels()->syncWithoutDetaching([$hotel->id]);
        $user->assignRole($role);

        $registrar->setPermissionsTeamId($previousTeam);

        return [(int) $hotel->id, $user];
    }

    public function test_a_user_without_the_required_permission_does_not_see_the_notification(): void
    {
        [$hotelId, $privilegedUser] = $this->seedHotelAndStaff(['manage pricing plans']);
        [, $unprivilegedUser] = $this->seedHotelAndStaff([]);

        app(NotificationService::class)->notify(
            $hotelId,
            'pricing_changed',
            'NOTIFICATIONS.ROOM_PRICE_UPDATED_TITLE',
            ['room' => 'Deluxe'],
            '/admin/pricing',
            'manage pricing plans'
        );

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($hotelId);

        $this->assertSame(1, AppNotification::visibleTo($privilegedUser, $hotelId)->count());
        $this->assertSame(0, AppNotification::visibleTo($unprivilegedUser, $hotelId)->count());
    }

    public function test_a_notification_with_no_required_permission_is_visible_to_everyone(): void
    {
        [$hotelId, $user] = $this->seedHotelAndStaff([]);

        app(NotificationService::class)->notify($hotelId, 'new_booking', 'NOTIFICATIONS.NEW_BOOKING_TITLE', [], null, null);

        app(PermissionRegistrar::class)->setPermissionsTeamId($hotelId);
        $this->assertSame(1, AppNotification::visibleTo($user, $hotelId)->count());
    }

    public function test_a_hotel_scoped_notification_is_not_visible_to_a_different_hotels_staff(): void
    {
        [$hotelIdA, $userA] = $this->seedHotelAndStaff(['view reservations']);
        [$hotelIdB, ] = $this->seedHotelAndStaff(['view reservations']);

        app(NotificationService::class)->notify($hotelIdA, 'new_booking', 'NOTIFICATIONS.NEW_BOOKING_TITLE', [], null, 'view reservations');

        app(PermissionRegistrar::class)->setPermissionsTeamId($hotelIdA);
        $this->assertSame(1, AppNotification::visibleTo($userA, $hotelIdA)->count());
        $this->assertSame(0, AppNotification::visibleTo($userA, $hotelIdB)->count());
    }

    public function test_a_global_notification_with_null_hotel_id_is_visible_regardless_of_hotel(): void
    {
        [$hotelId, $user] = $this->seedHotelAndStaff(['manage room types']);

        // Room types have no hotel_id in this schema — a price change notice
        // is global, not scoped to the hotel where the change was made from.
        // Other tests in the same (never-reset) run may have already created
        // their own hotel_id=null rows, so this checks THIS row is included
        // rather than asserting an absolute count of every global row.
        $notification = app(NotificationService::class)->notify(null, 'pricing_changed', 'NOTIFICATIONS.ROOM_PRICE_UPDATED_TITLE', ['room' => 'Standard'], '/admin/pricing', 'manage room types');

        app(PermissionRegistrar::class)->setPermissionsTeamId($hotelId);
        $this->assertTrue(AppNotification::visibleTo($user, $hotelId)->pluck('id')->contains($notification->id));
        $this->assertTrue(AppNotification::visibleTo($user, 999999)->pluck('id')->contains($notification->id));
    }

    public function test_index_endpoint_returns_only_notifications_the_authenticated_user_may_see(): void
    {
        [$hotelId, $user] = $this->seedHotelAndStaff(['view reservations']);

        app(NotificationService::class)->notify($hotelId, 'new_booking', 'NOTIFICATIONS.NEW_BOOKING_TITLE', ['channel' => 'Booking.com'], '/booking/reservations/1', 'view reservations');
        app(NotificationService::class)->notify($hotelId, 'pricing_changed', 'NOTIFICATIONS.ROOM_PRICE_UPDATED_TITLE', ['room' => 'Deluxe'], '/admin/pricing', 'manage pricing plans');

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->getJson('/api/users/notifications');

        $response->assertOk();
        $notifications = $response->json('data.notifications');
        $this->assertCount(1, $notifications);
        $this->assertSame('new_booking', $notifications[0]['type']);
        $this->assertSame(1, $response->json('data.unread_count'));
    }

    public function test_mark_seen_resets_the_unread_count(): void
    {
        [$hotelId, $user] = $this->seedHotelAndStaff(['view reservations']);
        app(NotificationService::class)->notify($hotelId, 'new_booking', 'NOTIFICATIONS.NEW_BOOKING_TITLE', [], null, 'view reservations');

        $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/notifications/seen')
            ->assertOk();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->getJson('/api/users/notifications');

        $this->assertSame(0, $response->json('data.unread_count'));
        $this->assertCount(1, $response->json('data.notifications'), 'marking seen must not delete/hide the item itself, only clear the unread count');
    }
}
