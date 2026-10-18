<?php

namespace Tests\Feature\Broadcast;

use Illuminate\Support\Facades\Config;
use Laravel\Passport\Passport;
use Tests\TestCase;

class BroadcastChannelAuthBrowserStyleTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('broadcasting.default', 'log');
        $this->app->forgetInstance(\Illuminate\Broadcasting\BroadcastManager::class);

        // createToken() needs a real Passport personal access client row —
        // normally created once by AdminSeeder, but this test doesn't run
        // the full db:seed and can't rely on another test having done so.
        if (! \Laravel\Passport\Client::query()->where('personal_access_client', true)->exists()) {
            \Illuminate\Support\Facades\Artisan::call('passport:client', [
                '--personal' => true,
                '--name' => 'Test Personal Access Client',
                '--no-interaction' => true,
            ]);
        }
    }

    public function test_broadcast_auth_with_form_body_and_bearer_token_like_browser(): void
    {
        $user = $this->userWithOnlyPermissions(['view reservations']);
        $token = $user->createToken('browser-style')->accessToken;

        $response = $this->call(
            'POST',
            '/api/broadcasting/auth',
            [
                'channel_name' => 'private-hotel.operations',
                'socket_id' => '1234.5678',
            ],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ]
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    }

    public function test_broadcast_auth_denies_when_bearer_missing_on_form_request(): void
    {
        $response = $this->call(
            'POST',
            '/api/broadcasting/auth',
            [
                'channel_name' => 'private-hotel.operations',
                'socket_id' => '1234.5678',
            ],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ]
        );

        $this->assertSame(401, $response->getStatusCode());
    }
}
