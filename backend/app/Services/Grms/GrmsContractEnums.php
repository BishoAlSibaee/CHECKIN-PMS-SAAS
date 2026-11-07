<?php

namespace App\Services\Grms;

use App\Models\Reservation;

/**
 * Maps our internal enum values to the GRMS v1 contract's closed string
 * vocabularies (docs/pms-contract.md section 3b, in the GRMS repo) — a value
 * outside the list is refused by GRMS with `invalid_value`. Discovered via
 * the real cross-system link test: our outbound payloads were sending raw
 * internal integer/enum codes instead of these contract strings.
 */
class GrmsContractEnums
{
    /**
     * payment/deposit state and checked-in/out are deliberately NOT part of
     * this — those travel only as the separate stay.* events (contract 3b).
     * We have no internal "no_show" status today, so it's never produced.
     */
    private const RESERVATION_STATUS = [
        Reservation::STATUS_CONFIRMED => 'confirmed',
        Reservation::STATUS_CANCELLED => 'cancelled',
        Reservation::STATUS_UNCONFIRMED => 'pending',
        Reservation::STATUS_PENDING_PAYMENT => 'pending',
    ];

    private const GUEST_ID_TYPE = ['ID' => 'id', 'PASSPORT' => 'passport'];

    public static function reservationStatus(int $status): string
    {
        return self::RESERVATION_STATUS[$status] ?? 'pending';
    }

    public static function guestIdType(?string $idType): string
    {
        return self::GUEST_ID_TYPE[$idType] ?? 'other';
    }
}
