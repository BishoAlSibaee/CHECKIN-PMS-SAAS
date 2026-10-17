<?php

namespace App\Services;

class PropertyPermissionBundle
{
    /** Minimum read permissions required for the Property admin screen. */
    public const VIEW_PERMISSIONS = [
        'view buildings',
        'view floors',
        'view suites',
        'view rooms',
        'view room types',
    ];

    /** Selecting any of these implies the user intends to work in Property. */
    private const TRIGGER_PERMISSIONS = [
        'view buildings',
        'view floors',
        'view suites',
        'view rooms',
        'view room types',
        'manage buildings',
        'manage floors',
        'manage suites',
        'manage rooms',
        'manage room types',
        'manage facilities',
    ];

    public static function expand(array $permissions): array
    {
        if (count(array_intersect($permissions, self::TRIGGER_PERMISSIONS)) === 0) {
            return $permissions;
        }

        return array_values(array_unique([...$permissions, ...self::VIEW_PERMISSIONS]));
    }
}
