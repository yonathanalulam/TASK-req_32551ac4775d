<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Crypto;

use Meridian\Infrastructure\Crypto\AesGcmCipher;
use PHPUnit\Framework\TestCase;

final class AesGcmCipherTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $key = bin2hex(random_bytes(32));
        $cipher = new AesGcmCipher($key, 1);
        $plain = 'The quick brown fox jumps over the lazy dog.';
        $envelope = $cipher->encrypt($plain);
        self::assertStringStartsWith('v1:', $envelope);
        self::assertSame($plain, $cipher->decrypt($envelope));
    }

    public function testDecryptsPriorKeyVersion(): void
    {
        $oldKey = bin2hex(random_bytes(32));
        $newKey = bin2hex(random_bytes(32));
        $old = new AesGcmCipher($oldKey, 1);
        $envelope = $old->encrypt('legacy data');
        $rotated = new AesGcmCipher($newKey, 2, ['1:' . $oldKey]);
        self::assertSame('legacy data', $rotated->decrypt($envelope));
    }

    public function testRejectsInvalidKey(): void
    {
        $this->expectException(\RuntimeException::class);
        new AesGcmCipher('not-hex', 1);
    }

    public function testRejectsTamperedCiphertext(): void
    {
        $cipher = new AesGcmCipher(bin2hex(random_bytes(32)), 1);
        $envelope = $cipher->encrypt('sensitive');
        $tampered = substr_replace($envelope, 'A', -4, 1);
        $this->expectException(\RuntimeException::class);
        $cipher->decrypt($tampered);
    }
}
