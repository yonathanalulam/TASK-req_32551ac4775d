<?php

declare(strict_types=1);

namespace Meridian\Infrastructure\Crypto;

interface Cipher
{
    /** Encrypts the plaintext and returns opaque ciphertext envelope (string safe for varchar columns). */
    public function encrypt(string $plaintext): string;

    /** Decrypts an envelope produced by encrypt() or previous key versions. */
    public function decrypt(string $envelope): string;

    /** Returns the current key version used by encrypt(). */
    public function currentKeyVersion(): int;
}
