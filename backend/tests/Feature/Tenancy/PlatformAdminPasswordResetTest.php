<?php

namespace Tests\Feature\Tenancy;

use App\Mail\PlatformAdminResetPasswordMail;
use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PlatformAdminPasswordResetTest extends TestCase
{
    use DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function platformAdmin(): PlatformAdmin
    {
        $suffix = (string) random_int(10000000, 99999999);

        return PlatformAdmin::create([
            'name' => 'Reset Test '.$suffix,
            'email' => 'reset-test-'.$suffix.'@test.local',
            'password' => bcrypt('old-password'),
            'is_active' => true,
        ]);
    }

    public function test_forgot_password_sends_a_reset_email_for_a_known_account(): void
    {
        Mail::fake();
        $admin = $this->platformAdmin();

        $response = $this->postJson('/api/platform-admin/forgot-password', ['email' => $admin->email]);

        $this->assertApiSuccess($response);
        Mail::assertSent(PlatformAdminResetPasswordMail::class, fn ($mail) => $mail->hasTo($admin->email));
    }

    public function test_forgot_password_gives_the_same_response_for_an_unknown_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/platform-admin/forgot-password', ['email' => 'nobody-'.uniqid().'@test.local']);

        // Same shape/status as the known-account case — no enumeration signal.
        $this->assertApiSuccess($response);
        Mail::assertNothingSent();
    }

    public function test_reset_password_with_a_valid_token_changes_the_password_and_revokes_tokens(): void
    {
        $admin = $this->platformAdmin();
        $token = Password::broker('platform_admins')->createToken($admin);
        $admin->createToken('old-session'); // should be revoked by a successful reset

        $response = $this->postJson('/api/platform-admin/reset-password', [
            'email' => $admin->email,
            'token' => $token,
            'password' => 'a-new-strong-password',
            'password_confirmation' => 'a-new-strong-password',
        ]);

        $this->assertApiSuccess($response);
        $this->assertTrue(Hash::check('a-new-strong-password', $admin->fresh()->password));
        $this->assertSame(0, $admin->tokens()->count());

        // The login endpoint accepts the new password immediately.
        $this->postJson('/api/platform-admin/login', [
            'email' => $admin->email,
            'password' => 'a-new-strong-password',
        ])->assertOk();
    }

    public function test_reset_password_rejects_an_invalid_token(): void
    {
        $admin = $this->platformAdmin();

        $response = $this->postJson('/api/platform-admin/reset-password', [
            'email' => $admin->email,
            'token' => 'not-a-real-token',
            'password' => 'a-new-strong-password',
            'password_confirmation' => 'a-new-strong-password',
        ]);

        $response->assertStatus(422);
        $this->assertTrue(Hash::check('old-password', $admin->fresh()->password));
    }

    public function test_reset_password_rejects_a_mismatched_confirmation(): void
    {
        $admin = $this->platformAdmin();
        $token = Password::broker('platform_admins')->createToken($admin);

        $response = $this->postJson('/api/platform-admin/reset-password', [
            'email' => $admin->email,
            'token' => $token,
            'password' => 'a-new-strong-password',
            'password_confirmation' => 'does-not-match',
        ]);

        $response->assertStatus(422);
    }
}
