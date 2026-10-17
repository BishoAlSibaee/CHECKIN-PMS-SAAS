<?php

namespace App\Support;

class ReservationTestDataGuard
{
    /** @var list<string> */
    private const ISOLATED_DATABASES = [
        ':memory:',
        'checkinbooking_test',
    ];

    /** @var list<string> */
    private const PROTECTED_DEV_DATABASES = [
        'checkinbooking',
    ];

    public static function isIsolatedDatabase(): bool
    {
        $connection = (string) config('database.default', 'mysql');
        $database = (string) config("database.connections.{$connection}.database", '');

        if (in_array($database, self::ISOLATED_DATABASES, true)) {
            return true;
        }

        return str_ends_with($database, '_test');
    }

    public static function isProtectedDevDatabase(): bool
    {
        $connection = (string) config('database.default', 'mysql');
        $database = (string) config("database.connections.{$connection}.database", '');

        return in_array($database, self::PROTECTED_DEV_DATABASES, true);
    }

    /**
     * Whether ReservationTestDataSeeder may delete demo-year reservations.
     */
    public static function mayPurgeDemoYearReservations(): bool
    {
        if (filter_var(env('SEED_RESERVATIONS_FRESH'), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        if (filter_var(env('DEMO_SEED'), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        if (filter_var(env('ALLOW_RESERVATION_SEED_PURGE'), FILTER_VALIDATE_BOOL)) {
            return self::isIsolatedDatabase();
        }

        return false;
    }

    /**
     * Block PHPUnit when it would run against the local dev database.
     */
    public static function shouldBlockPHPUnit(): bool
    {
        return app()->environment('testing')
            && self::isProtectedDevDatabase()
            && ! self::isIsolatedDatabase();
    }
}
