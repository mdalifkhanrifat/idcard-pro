<?php

namespace App\Services\Biometrics;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Encrypted storage of biometric images and templates. Files get random names under the
 * organization's folder; their contents are encrypted with the biometric key (config/biometrics.php),
 * never stored or returned in the clear except to the caller that reads them.
 */
class BiometricVault
{
    private ?Encrypter $encrypter = null;

    /**
     * Encrypt and store; returns where it was put.
     *
     * @return array{disk: string, path: string}
     */
    public function put(string $tenantPublicId, string $contents): array
    {
        $disk = (string) config('biometrics.disk');
        $path = "biometrics/{$tenantPublicId}/".Str::lower((string) Str::ulid()).'.bin';
        Storage::disk($disk)->put($path, $this->encrypter()->encryptString($contents));

        return ['disk' => $disk, 'path' => $path];
    }

    /**
     * Read and decrypt a stored file (also one saved with a previous key).
     */
    public function get(string $disk, string $path): string
    {
        $payload = Storage::disk($disk)->get($path);
        if ($payload === null) {
            throw new RuntimeException("Biometric file {$path} is missing.");
        }

        return $this->encrypter()->decryptString($payload);
    }

    /**
     * Remove stored files once their records are gone.
     *
     * @param  Collection<int, array{disk: string, path: string}>  $locations
     */
    public function delete(Collection $locations): void
    {
        $locations->groupBy('disk')
            ->each(fn (Collection $group, string $disk) => Storage::disk($disk)->delete($group->pluck('path')->all()));
    }

    /**
     * Turn a configured key ("base64:..." as in APP_KEY) into raw bytes.
     */
    public static function parseKey(string $key): string
    {
        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
    }

    private function encrypter(): Encrypter
    {
        if ($this->encrypter !== null) {
            return $this->encrypter;
        }
        $key = (string) config('biometrics.key');
        if ($key === '') {
            // Never fall back to storing biometrics unencrypted or under APP_KEY
            throw new RuntimeException('BIOMETRIC_KEY is not set. Run "php artisan app:biometric-key".');
        }
        $cipher = (string) config('biometrics.cipher');
        $encrypter = new Encrypter(self::parseKey($key), $cipher);

        return $this->encrypter = $encrypter->previousKeys(array_map(self::parseKey(...), (array) config('biometrics.previous_keys')));
    }
}
