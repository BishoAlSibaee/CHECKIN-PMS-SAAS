<?php

namespace App\Http\Controllers;

use App\Models\PlatformAdmin;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Auth entry point for the standalone PlatformAdmin app only — see
 * App\Models\PlatformAdmin and the "platform_admin" guard in config/auth.php
 * for why this is deliberately not UsersController::login().
 */
class PlatformAdminAuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $admin = PlatformAdmin::where('email', $request->email)->first();

        if (!$admin || !$admin->is_active || !Hash::check($request->password, $admin->password)) {
            return \Failed('Invalid email or password.', 401);
        }

        $token = $admin->createToken('platform-admin-app')->plainTextToken;

        return \SuccessData('Logged in', [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'token' => $token,
        ]);
    }

    public function me(Request $request)
    {
        return \SuccessData('OK', $request->user());
    }

    /**
     * Deliberately returns the same success message whether or not the
     * email belongs to an account — sendResetLink() itself already no-ops
     * silently for an unknown email, so there's nothing here that would let
     * a caller enumerate which emails are registered.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        Password::broker('platform_admins')->sendResetLink(
            $request->only('email')
        );

        return \SuccessData('If that email is registered, a reset link has been sent.', null);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::broker('platform_admins')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (PlatformAdmin $admin, string $password) {
                $admin->forceFill(['password' => Hash::make($password)])->save();
                $admin->tokens()->delete();

                event(new PasswordReset($admin));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return \Failed(Str::ucfirst(__($status)), 422);
        }

        return \SuccessData('Password reset successfully.', null);
    }
}
