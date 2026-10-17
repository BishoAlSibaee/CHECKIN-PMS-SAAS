<?php

namespace App\Support;

class RefundPolicyPaymentStatus
{
    public const NONE = 'none';

    public const PARTIAL = 'partial';

    public const FULL = 'full';

    /** Any net payment > 0 (partial or full). */
    public const PAID = 'paid';

    /**
     * Statuses allowed when configuring a policy (excludes unpaid — use reservation cancel instead).
     *
     * @return array<int, string>
     */
    public static function selectable(): array
    {
        return [self::PARTIAL, self::FULL, self::PAID];
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_merge([self::NONE], self::selectable());
    }

    /**
     * @param  array<int, string>|null  $statuses
     */
    public static function matches(?array $statuses, int $contextStatus, float $netPaid): bool
    {
        $normalized = self::normalize($statuses);

        if ($normalized === []) {
            return true;
        }

        foreach ($normalized as $status) {
            if ($status === self::PAID && $netPaid > 0.005) {
                return true;
            }
            if ($status === self::PARTIAL && $contextStatus === 1) {
                return true;
            }
            if ($status === self::FULL && $contextStatus === 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>|null  $statuses
     * @return array<int, string>
     */
    public static function normalize(?array $statuses): array
    {
        if ($statuses === null || $statuses === []) {
            return [];
        }

        $allowed = array_flip(self::selectable());

        return array_values(array_unique(array_filter(
            array_map('strval', $statuses),
            fn (string $status) => isset($allowed[$status])
        )));
    }

    /**
     * @param  array<int, string>|null  $statuses
     */
    public static function legacySingleStatus(?array $statuses): ?int
    {
        $normalized = self::normalize($statuses);

        if (count($normalized) !== 1) {
            return null;
        }

        return match ($normalized[0]) {
            self::PARTIAL => 1,
            self::FULL => 2,
            default => null,
        };
    }
}
