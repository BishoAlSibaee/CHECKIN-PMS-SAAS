<?php

namespace App\Observers;

use App\Models\Reservation;

class ReservationObserver
{
    /**
     * Characters chosen to avoid guest transcription errors (no 0/O, 1/I/L confusion).
     */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const CODE_LENGTH = 7;
    private const MAX_ATTEMPTS = 5;

    public function creating(Reservation $reservation): void
    {
        if (!empty($reservation->confirmation_code)) {
            return;
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = $this->generateCode();

            if (!Reservation::where('confirmation_code', $code)->exists()) {
                $reservation->confirmation_code = $code;

                return;
            }
        }

        throw new \RuntimeException('Unable to generate a unique reservation confirmation code.');
    }

    private function generateCode(): string
    {
        $alphabetLength = strlen(self::CODE_ALPHABET);
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $code;
    }
}
