<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Auth;

use Meridian\Application\Exceptions\ValidationException;
use Meridian\Domain\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function testHashAndVerify(): void
    {
        $h = new PasswordHasher(cost: 4);
        $hash = $h->hash('correct horse battery');
        self::assertTrue($h->verify('correct horse battery', $hash));
        self::assertFalse($h->verify('wrong', $hash));
    }

    public function testRejectsShortPasswords(): void
    {
        $this->expectException(ValidationException::class);
        (new PasswordHasher(cost: 4))->hash('short');
    }
}
