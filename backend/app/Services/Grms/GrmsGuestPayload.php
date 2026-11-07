<?php

namespace App\Services\Grms;

use App\Models\Client;

/**
 * The contract's guest/primary_guest shape (docs/pms-contract.md section 5)
 * — shared between ReservationController's `primary_guest` on a reservation
 * event and the standalone `guest.updated` event, so both represent the
 * same client identically.
 */
class GrmsGuestPayload
{
    public static function build(Client $client): array
    {
        $mobile = $client->international_code && $client->mobile
            ? $client->international_code.$client->mobile
            : null;

        return [
            'id' => (string) $client->id,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'mobile' => $mobile,
            'email' => $client->email,
            'id_type' => GrmsContractEnums::guestIdType($client->IdType),
            'id_number' => $client->IdNumber,
            'nationality' => $client->nationality,
        ];
    }
}
