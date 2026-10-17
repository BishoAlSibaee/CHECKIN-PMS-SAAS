<?php

namespace App\Services\Zatca;

use RuntimeException;

class ZatcaCredentialStore
{
    /** Pre-multi-branch single-tenant path, kept as a read fallback only. */
    private function legacyPath(): string
    {
        return rtrim((string) config('zatca.storage_path'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'credentials.json';
    }

    public function path(?int $taxHotelId = null): string
    {
        if ($taxHotelId === null) {
            return $this->legacyPath();
        }

        return rtrim((string) config('zatca.storage_path'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'hotel_'.$taxHotelId
            .DIRECTORY_SEPARATOR
            .'credentials.json';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function save(array $payload, ?int $taxHotelId = null): string
    {
        $path = $this->path($taxHotelId);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create ZATCA credential directory.');
        }

        $payload['saved_at'] = now()->toIso8601String();
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600);

        return $path;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(?int $taxHotelId = null): ?array
    {
        $path = $this->path($taxHotelId);
        if (! is_file($path)) {
            // The very first hotel onboarded before per-hotel storage existed
            // still has its certificate under the old single-tenant path —
            // read it from there rather than forcing a re-onboard.
            $path = $this->legacyPath();
            if (! is_file($path)) {
                return null;
            }
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
