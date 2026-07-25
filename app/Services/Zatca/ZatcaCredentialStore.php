<?php

namespace App\Services\Zatca;

use RuntimeException;

class ZatcaCredentialStore
{
    public function path(): string
    {
        return rtrim((string) config('zatca.storage_path'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'credentials.json';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function save(array $payload): string
    {
        $directory = dirname($this->path());
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create ZATCA credential directory.');
        }

        $payload['saved_at'] = now()->toIso8601String();
        file_put_contents($this->path(), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($this->path(), 0600);

        return $this->path();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(): ?array
    {
        if (! is_file($this->path())) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($this->path()), true);

        return is_array($decoded) ? $decoded : null;
    }
}
