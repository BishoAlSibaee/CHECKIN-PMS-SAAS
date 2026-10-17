<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

/**
 * Resolves which hotel (branch) the current request operates on, and scopes
 * Spatie's role/permission checks to it for the rest of the request (see
 * config/permission.php — "teams" is enabled with team_foreign_key=hotel_id).
 *
 * The client sends the hotel it wants via the X-Hotel-Id header (see the
 * frontend's HotelContextService + auth interceptor); if omitted, this falls
 * back to the user's first accessible hotel so clients that haven't been
 * updated yet (or the kiosk's own auth path, which never sends this header)
 * keep working exactly as before multi-hotel support existed.
 *
 * Must run after auth:api has resolved the user, and before any permission/
 * role/role_or_permission middleware on the same route.
 */
class ResolveCurrentHotel
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if (! $user) {
            return \Failed('Not authenticated', 401);
        }

        $requestedHotelId = $request->header('X-Hotel-Id');
        $accessibleHotels = $user->hotels()->with('tenant')->get(['hotels.id', 'hotels.tenant_id']);

        if ($accessibleHotels->isEmpty()) {
            return \Failed('This account is not assigned to any hotel.', 403);
        }

        if ($requestedHotelId !== null) {
            $requestedHotelId = (int) $requestedHotelId;
            $hotel = $accessibleHotels->firstWhere('id', $requestedHotelId);
            if (! $hotel) {
                return \Failed('You do not have access to the requested hotel.', 403);
            }
        } else {
            $hotel = $accessibleHotels->first();
        }

        // Defense in depth for a session already in progress when the
        // subscription expired or the tenant was deactivated — the primary
        // check is at login time (see UsersController::login).
        if ($hotel->tenant && ! $hotel->tenant->is_active) {
            return \Failed('This account has been deactivated.', 403);
        }
        if ($hotel->tenant && ! $hotel->tenant->isSubscriptionActive()) {
            return \Failed('This subscription has expired.', 403);
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($hotel->id);
        $request->attributes->set('currentHotelId', $hotel->id);
        $request->attributes->set('currentTenantId', $hotel->tenant_id);

        return $next($request);
    }
}
