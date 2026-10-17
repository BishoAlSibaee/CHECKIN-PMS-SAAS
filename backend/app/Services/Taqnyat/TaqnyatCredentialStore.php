<?php

namespace App\Services\Taqnyat;

use RuntimeException;

/**
 * Same pattern as App\Services\Zatca\ZatcaCredentialStore: a per-hotel JSON
 * file on disk, not a database column — protection comes from filesystem
 * permissions (0700 dir / 0600 file), not encryption.
 */
class TaqnyatCredentialStore
{
    public function path(int $hotelId): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'taqnyat'.DIRECTORY_SEPARATOR.'hotel_'.$hotelId.DIRECTORY_SEPARATOR.'credentials.json');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function save(array $payload, int $hotelId): string
    {
        $path = $this->path($hotelId);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create Taqnyat credential directory.');
        }

        $payload['saved_at'] = now()->toIso8601String();
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600);

        return $path;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(int $hotelId): ?array
    {
        $path = $this->path($hotelId);
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
