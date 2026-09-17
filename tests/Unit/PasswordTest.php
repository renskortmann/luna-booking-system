<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use Macrolab\Config;
use Macrolab\Password;
use PHPUnit\Framework\TestCase;

final class PasswordTest extends TestCase
{
    protected function setUp(): void
    {
        Config::set(['auth' => ['password_min_length' => 12]]);
    }

    public function testHashAndVerifyRoundTrip(): void
    {
        $hash = Password::hash('correct horse battery staple');

        self::assertTrue(Password::verify('correct horse battery staple', $hash));
        self::assertFalse(Password::verify('incorrect horse battery staple', $hash));
    }

    public function testHashesAreSaltedSoTwoAreNeverEqual(): void
    {
        self::assertNotSame(Password::hash('the same password'), Password::hash('the same password'));
    }

    public function testVerifyRefusesAnEmptyHash(): void
    {
        self::assertFalse(Password::verify('anything', ''));
    }

    public function testAlgorithmIsOneWeAskedFor(): void
    {
        self::assertContains(Password::algorithm(), ['argon2id', 'bcrypt']);
    }

    public function testPolicyRejectsShortPasswords(): void
    {
        self::assertNotNull(Password::policyError('short'));
        self::assertNotNull(Password::policyError('elevenchars'));   // 11
        self::assertNull(Password::policyError('twelvechars!'));     // 12
    }

    public function testPolicyRejectsTheNetidInThePassword(): void
    {
        $error = Password::policyError('jdoe-is-my-password', 'jdoe');

        self::assertNotNull($error);
        self::assertStringContainsString('netID', $error);
    }

    public function testPolicyRejectsCommonPasswords(): void
    {
        // Long enough to pass the length rule, but on the denylist.
        self::assertNotNull(Password::policyError('passwordpassword'));
        self::assertNotNull(Password::policyError('PasswordPassword'), 'the check is case-insensitive');
    }

    public function testPolicyAcceptsALongPassphrase(): void
    {
        self::assertNull(Password::policyError('tiny bicycles argue loudly', 'jdoe'));
    }

    public function testPolicyRejectsAbsurdLength(): void
    {
        self::assertNotNull(Password::policyError(str_repeat('a', 5000)));
    }

    public function testDummyVerifyDoesNotThrow(): void
    {
        Password::dummyVerify('whatever');

        self::assertTrue(true);
    }
}
