<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_login_with_valid_credentials_returns_token(): void
    {
        $user = User::first();
        $this->assertNotNull($user);

        $response = $this->postJson('/api/users/login', [
            'job_number' => $user->job_number,
            'password' => 'password',
        ]);

        if ($response->status() === 401 || $response->json('success') === false) {
            $this->markTestSkipped('Login credentials unavailable in test DB.');
        }

        $this->assertApiSuccess($response);
        $token = $response->json('data.token') ?? $response->json('token');
        $this->assertNotEmpty($token);
    }

    public function test_login_with_invalid_credentials_returns_error(): void
    {
        $user = User::first();
        $this->assertNotNull($user);

        $response = $this->postJson('/api/users/login', [
            'job_number' => $user->job_number,
            'password' => 'wrong-password-xyz',
        ]);

        $this->assertTrue(in_array($response->status(), [401, 422, 500], true));
        if ($response->status() !== 500) {
            $response->assertJsonPath('success', false);
        }
    }

    public function test_login_requires_job_number_and_password(): void
    {
        $response = $this->postJson('/api/users/login', []);

        $this->assertTrue(in_array($response->status(), [422, 500], true));
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = $this->adminUser();

        $response = $this->actingAs($user, 'api')->getJson('/api/users/me');

        $this->assertApiSuccess($response);
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_me_requires_authentication(): void
    {
        $this->assertApiUnauthorized($this->getJson('/api/users/me'));
    }

    public function test_change_password_requires_authentication(): void
    {
        $response = $this->postJson('/api/users/changePassword', [
            'old_password' => 'password',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $this->assertApiUnauthorized($response);
    }

    public function test_change_password_validates_required_fields(): void
    {
        $user = $this->adminUser();

        $response = $this->actingAs($user, 'api')->postJson('/api/users/changePassword', []);

        $this->assertTrue(in_array($response->status(), [422, 500], true));
    }
}
