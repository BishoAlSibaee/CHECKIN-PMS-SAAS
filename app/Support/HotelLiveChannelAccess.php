<?php

namespace App\Support;

use App\Models\User;

/**
 * Who may subscribe to the private hotel.operations live channel.
 * Events carry scope hints only — API routes still enforce RBAC on data.
 */
final class HotelLiveChannelAccess
{
    /** Any of these API permissions grants live channel access. */
    public const PERMISSIONS = [
        'view reservations',
        'create reservations',
        'update reservations',
        'cancel reservations',
        'view payments',
        'view revenue',
        'view earnings',
        'view reports',
        'view financial reports',
        'view accounting reports',
        'view buildings',
        'view floors',
        'view suites',
        'view rooms',
        'manage rooms',
        'view clients',
        'view users',
    ];

    public static function allows(?User $user): bool
    {
        if (!$user || !(int) ($user->active ?? 1)) {
            return false;
        }

        // Any authenticated active staff may subscribe; payloads are scope hints only.
        return true;
    }
}
