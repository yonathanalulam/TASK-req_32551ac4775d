<?php

declare(strict_types=1);

namespace Meridian\Infrastructure\Crypto;

use RuntimeException;

/**
 * AES-256-GCM cipher with per-ciphertext IV and versioned key identifier.
 *
 * Envelope format: v<version>:<base64(iv || tag || ciphertext)>
 * IV is 12 bytes; tag is 16 bytes; remaining is ciphertext.
 *
 * Previous keys are provided as "version:hex" strings so that decrypt() can still
 * unwrap legacy records during rotation, while encrypt() always uses the current key.
 */
final class AesGcmCipher implements Cipher
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    private string $currentKey;
    private int $currentVersion;
    /** @var array<int,string> */
    private array $keysByVersion;

    /** @param array<int,string> $previousKeys each entry "version:hex" */
    public function __construct(string $currentHex, int $currentVersion, array $previousKeys = [])
    {
        if (!$this->isValidHex32($currentHex)) {
            throw new RuntimeException('APP_MASTER_KEY must be 64 hex chars (256 bits).');
        }
        $this->currentKey = hex2bin($currentHex);
        $this->currentVersion = $currentVersion;
        $this->keysByVersion = [$currentVersion => $this->currentKey];
        foreach ($previousKeys as $entry) {
            $parts = explode(':', trim($entry), 2);
            if (count($parts) !== 2) {
                continue;
            }
            $v = (int) $parts[0];
            if ($this->isValidHex32($parts[1])) {
                $this->keysByVersion[$v] = hex2bin($parts[1]);
            }
        }
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->currentKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN,
        );
        if ($cipher === false) {
            throw new RuntimeException('encryption failed');
        }
        return 'v' . $this->currentVersion . ':' . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $envelope): string
    {
        if (!str_starts_with($envelope, 'v')) {
            throw new RuntimeException('invalid ciphertext envelope');
        }
        $sepPos = strpos($envelope, ':');
        if ($sepPos === false) {
            throw new RuntimeException('invalid ciphertext envelope');
        }
        $version = (int) substr($envelope, 1, $sepPos - 1);
        $payload = base64_decode(substr($envelope, $sepPos + 1), true);
        if ($payload === false || strlen($payload) < self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('invalid ciphertext payload');
        }
        $key = $this->keysByVersion[$version] ?? null;
        if ($key === null) {
            throw new RuntimeException('unknown key version ' . $version);
        }
        $iv = substr($payload, 0, self::IV_LEN);
        $tag = substr($payload, self::IV_LEN, self::TAG_LEN);
        $cipher = substr($payload, self::IV_LEN + self::TAG_LEN);
        $plain = openssl_decrypt(
            $cipher,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );
        if ($plain === false) {
            throw new RuntimeException('decryption failed');
        }
        return $plain;
    }

    public function currentKeyVersion(): int
    {
        return $this->currentVersion;
    }

    private function isValidHex32(string $hex): bool
    {
        return preg_match('/^[0-9a-fA-F]{64}$/', $hex) === 1;
    }
}
