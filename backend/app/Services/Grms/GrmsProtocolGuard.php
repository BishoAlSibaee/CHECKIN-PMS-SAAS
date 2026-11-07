<?php

namespace App\Services\Grms;

use App\Models\Hotel;

/**
 * A hotel is on exactly one GRMS protocol at a time (hotels.pms_protocol) —
 * the v1 contract's own routes refuse a 'legacy' hotel and vice versa
 * (409 other_protocol), so a hotel mid-migration can't get events applied
 * twice through both paths at once.
 */
class GrmsProtocolGuard
{
    public static function allows(int $hotelId, string $expectedProtocol): bool
    {
        $actual = Hotel::where('id', $hotelId)->value('pms_protocol') ?? 'legacy';

        return $actual === $expectedProtocol;
    }
}
