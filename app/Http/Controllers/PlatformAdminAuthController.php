<?php

namespace App\Http\Controllers;

use App\Models\PlatformAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
}
