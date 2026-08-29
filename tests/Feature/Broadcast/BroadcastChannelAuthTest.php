<?php

namespace Tests\Feature\Broadcast;

use Illuminate\Support\Facades\Config;
use Laravel\Passport\Passport;
use Tests\TestCase;

class BroadcastChannelAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('broadcasting.default', 'log');
        $this->app->forgetInstance(\Illuminate\Broadcasting\BroadcastManager::class);
    }

    private function authPayload(): array
    {
        return [
            'channel_name' => 'private-hotel.operations',
            'socket_id'    => '1234.5678',
        ];
    }

    public function test_broadcast_auth_requires_authentication(): void
    {
        $response = $this->postJson('/api/broadcasting/auth', $this->authPayload());

        $response->assertStatus(401);
    }

    public function test_hotel_operations_channel_allows_view_reservations_permission(): void
    {
        $user = $this->userWithOnlyPermissions(['view reservations']);

        $this->assertTrue($user->hasPermissionTo('view reservations', 'api'));
    }

    public function test_hotel_operations_channel_allows_view_payments_without_view_reservations(): void
    {
        $user = $this->userWithOnlyPermissions(['view payments']);

        Passport::actingAs($user, [], 'api');

        $response = $this->postJson('/api/broadcasting/auth', $this->authPayload());

        $response->assertStatus(200);
    }

    public function test_hotel_operations_channel_denies_without_view_reservations(): void
    {
        $user = $this->userWithOnlyPermissions(['manage facilities']);
        $user->update(['active' => 0]);

        $this->assertFalse(\App\Support\HotelLiveChannelAccess::allows($user->fresh()));
    }

    public function test_hotel_operations_channel_allows_active_authenticated_user(): void
    {
        $user = $this->userWithOnlyPermissions(['manage facilities']);
        $user->update(['active' => 1]);

        $this->assertTrue(\App\Support\HotelLiveChannelAccess::allows($user->fresh()));
    }

    public function test_broadcast_auth_accepts_authenticated_staff_with_view_reservations(): void
    {
        $user = $this->userWithOnlyPermissions(['view reservations']);
        Passport::actingAs($user, [], 'api');

        $response = $this->postJson('/api/broadcasting/auth', $this->authPayload());

        $response->assertStatus(200);
    }
}
