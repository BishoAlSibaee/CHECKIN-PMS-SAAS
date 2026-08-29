<?php

namespace App\Http\Middleware;

use App\Models\KioskDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticateKioskDevice
{
    public function handle(Request $request, Closure $next)
    {
        $token = $this->resolveBearerToken($request);

        if (!$token) {
            return \Failed('Missing kiosk device token', 401);
        }

        $device = KioskDevice::with('user')
            ->where('token_hash', hash('sha256', $token))
            ->where('active', true)
            ->first();

        if (!$device || !$device->user) {
            return \Failed('Invalid or inactive kiosk device token', 401);
        }

        Auth::guard('api')->setUser($device->user);
        $request->attributes->set('kioskDevice', $device);

        $device->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }

    /**
     * Some server/SAPI configurations (PHP-FPM behind Nginx without an explicit
     * fastcgi_param pass-through, and even PHP's built-in dev server in some
     * setups) don't reliably surface the Authorization header into Symfony's
     * parsed header bag, even though it's present in $_SERVER / getallheaders().
     * Fall back to those sources rather than trusting bearerToken() alone.
     */
    private function resolveBearerToken(Request $request): ?string
    {
        $header = $request->bearerToken();
        if ($header) {
            return $header;
        }

        $raw = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        if (!$raw && function_exists('getallheaders')) {
            $all = getallheaders();
            $raw = $all['Authorization'] ?? $all['authorization'] ?? null;
        }

        if ($raw && str_starts_with($raw, 'Bearer ')) {
            return substr($raw, 7);
        }

        return null;
    }
}
