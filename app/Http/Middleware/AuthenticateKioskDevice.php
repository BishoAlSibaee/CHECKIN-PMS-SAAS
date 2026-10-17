<?php

namespace App\Http\Middleware;

use App\Models\KioskDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;

class AuthenticateKioskDevice
{
    public function handle(Request $request, Closure $next)
    {
        $token = $this->resolveBearerToken($request);

        if (!$token) {
            return \Failed('Missing kiosk device token', 401);
        }

        $device = KioskDevice::with(['user', 'building.hotel.tenant'])
            ->where('token_hash', hash('sha256', $token))
            ->where('active', true)
            ->first();

        if (!$device || !$device->user) {
            return \Failed('Invalid or inactive kiosk device token', 401);
        }

        Auth::guard('api')->setUser($device->user);
        $request->attributes->set('kioskDevice', $device);

        // Spatie "teams" is enabled with team_foreign_key=hotel_id (multi-hotel
        // support) — every permission:/role: check on kiosk routes needs this
        // set, or it silently finds zero permissions for the device's user
        // regardless of what's actually assigned. The kiosk is physically tied
        // to one building, so its hotel is unambiguous.
        if ($device->building) {
            $tenant = $device->building->hotel?->tenant;

            // A kiosk device has no "login" to gate at — this middleware IS
            // its enforcement point, same role ResolveCurrentHotel plays for
            // staff sessions. Without this, a deactivated or expired tenant's
            // kiosk would keep accepting check-ins/payments indefinitely.
            if ($tenant && !$tenant->is_active) {
                return \Failed('This account has been deactivated.', 403);
            }
            if ($tenant && !$tenant->isSubscriptionActive()) {
                return \Failed('This subscription has expired.', 403);
            }

            app(PermissionRegistrar::class)->setPermissionsTeamId($device->building->hotel_id);
            $request->attributes->set('currentHotelId', $device->building->hotel_id);
            $request->attributes->set('currentTenantId', $device->building->hotel?->tenant_id);
        }

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
