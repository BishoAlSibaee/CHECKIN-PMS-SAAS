<?php

namespace App\Services\Grms;

use App\Models\GrmsHotelCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues/rotates the per-hotel, per-direction HMAC credentials the GRMS v1
 * contract requires (replacing the single global GRMS_API_TOKEN the legacy
 * integration uses). The raw secret is returned ONLY from generate()/rotate()
 * — same "shown once" discipline as CreateKioskDevice's token — callers must
 * capture it then; GrmsHotelCredential::secret is never exposed again after.
 */
class GrmsCredentialService
{
    /**
     * @return array{credential: GrmsHotelCredential, secret: string}
     */
    public function generate(int $hotelId, string $direction): array
    {
        return DB::transaction(function () use ($hotelId, $direction) {
            GrmsHotelCredential::where('hotel_id', $hotelId)
                ->where('direction', $direction)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $secret = Str::random(40);

            $credential = GrmsHotelCredential::create([
                'hotel_id' => $hotelId,
                'direction' => $direction,
                'key_id' => 'grms_'.Str::lower(Str::random(24)),
                'secret' => $secret,
                'is_active' => true,
                'rotated_at' => now(),
            ]);

            return ['credential' => $credential, 'secret' => $secret];
        });
    }

    public function rotate(int $hotelId, string $direction): array
    {
        return $this->generate($hotelId, $direction);
    }

    public function activeSecretFor(string $keyId): ?string
    {
        $credential = GrmsHotelCredential::where('key_id', $keyId)
            ->where('is_active', true)
            ->first();

        return $credential?->secret;
    }
}
