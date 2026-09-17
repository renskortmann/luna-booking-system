<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use Macrolab\Config;
use Macrolab\Crypto;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CryptoTest extends TestCase
{
    protected function setUp(): void
    {
        Config::set(['app' => ['key' => base64_encode(str_repeat('k', 32))]]);
    }

    public function testRoundTrip(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';

        self::assertSame($secret, Crypto::decrypt(Crypto::encrypt($secret)));
    }

    public function testCiphertextDiffersEachTime(): void
    {
        self::assertNotSame(Crypto::encrypt('same'), Crypto::encrypt('same'), 'a fresh nonce each time');
    }

    public function testTamperingIsDetected(): void
    {
        $blob = Crypto::encrypt('JBSWY3DPEHPK3PXP');
        $blob[strlen($blob) - 1] = $blob[strlen($blob) - 1] === 'a' ? 'b' : 'a';

        $this->expectException(RuntimeException::class);
        Crypto::decrypt($blob);
    }

    public function testADifferentKeyCannotDecrypt(): void
    {
        $blob = Crypto::encrypt('JBSWY3DPEHPK3PXP');

        Config::set(['app' => ['key' => base64_encode(str_repeat('x', 32))]]);

        $this->expectException(RuntimeException::class);
        Crypto::decrypt($blob);
    }

    public function testAMalformedKeyIsRefusedWithAnActionableMessage(): void
    {
        Config::set(['app' => ['key' => 'not-base64-of-32-bytes']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/generate-key/');
        Crypto::encrypt('x');
    }
}
