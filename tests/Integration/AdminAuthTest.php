<?php

declare(strict_types=1);

namespace Macrolab\Tests\Integration;

use Macrolab\AdminAuth;
use Macrolab\Clock;
use Macrolab\Crypto;
use Macrolab\Db;
use OTPHP\TOTP;
use RuntimeException;

/**
 * The administrator's two factors.
 */
final class AdminAuthTest extends DatabaseTestCase
{
    private const PASSWORD = 'a long enough test password';

    public function testCreatingTheAccountReturnsTheSecretAndRecoveryCodes(): void
    {
        $created = AdminAuth::create('admin', self::PASSWORD);

        self::assertNotSame('', $created['secret']);
        self::assertCount(10, $created['recovery_codes']);
        self::assertStringContainsString('otpauth://totp/', $created['uri']);
        self::assertTrue(AdminAuth::exists());
    }

    public function testTheAccountCannotBeCreatedTwice(): void
    {
        AdminAuth::create('admin', self::PASSWORD);

        $this->expectException(RuntimeException::class);
        AdminAuth::create('admin2', self::PASSWORD);
    }

    public function testAWeakPasswordIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        AdminAuth::create('admin', 'short');
    }

    public function testTheTotpSecretIsNotReadableInTheDatabase(): void
    {
        $created = AdminAuth::create('admin', self::PASSWORD);

        $stored = Db::get()->value('SELECT totp_secret_enc FROM admin_account');
        $stored = is_resource($stored) ? (string) stream_get_contents($stored) : (string) $stored;

        self::assertStringNotContainsString($created['secret'], $stored, 'stored encrypted, not in the clear');
        self::assertSame($created['secret'], Crypto::decrypt($stored), 'and recoverable with the app key');
    }

    public function testTheRightPasswordIsAcceptedAndTheWrongOneIsNot(): void
    {
        AdminAuth::create('admin', self::PASSWORD);

        self::assertNotNull(AdminAuth::verifyPassword('admin', self::PASSWORD));
        self::assertNull(AdminAuth::verifyPassword('admin', 'not the password'));
        self::assertNull(AdminAuth::verifyPassword('nobody', self::PASSWORD));
    }

    public function testAFailedPasswordIsRecordedForThrottling(): void
    {
        AdminAuth::create('admin', self::PASSWORD);
        AdminAuth::verifyPassword('admin', 'wrong');

        self::assertSame(1, (int) Db::get()->value(
            'SELECT COUNT(*) FROM login_attempts WHERE success = 0'
        ));
    }

    public function testAValidCodeFromTheAuthenticatorIsAccepted(): void
    {
        $created = AdminAuth::create('admin', self::PASSWORD);
        Clock::freeze('2026-09-14 10:00:00');

        self::assertTrue(AdminAuth::verifyTotp($created['id'], $this->codeFor($created['secret'])));
    }

    public function testAWrongCodeIsRejected(): void
    {
        $created = AdminAuth::create('admin', self::PASSWORD);

        self::assertFalse(AdminAuth::verifyTotp($created['id'], '000000'));
        self::assertFalse(AdminAuth::verifyTotp($created['id'], ''));
        self::assertFalse(AdminAuth::verifyTotp($created['id'], 'abcdef'));
    }

    /**
     * The reason totp_last_counter exists: a code that has been used once must
     * not work again inside its own 30-second window.
     */
    public function testACodeCannotBeUsedTwice(): void
    {
        $created = AdminAuth::create('admin', self::PASSWORD);
        Clock::freeze('2026-09-14 10:00:00');

        $code = $this->codeFor($created['secret']);

        self::assertTrue(AdminAuth::verifyTotp($created['id'], $code));
        self::assertFalse(AdminAuth::verifyTotp($created['id'], $code), 'replay must be refused');
    }

    public function testACodeFromTheNextStepStillWorksAfterOneIsSpent(): void
    {
        $created = AdminAuth::create('admin', self::PASSWORD);
        Clock::freeze('2026-09-14 10:00:00');
        AdminAuth::verifyTotp($created['id'], $this->codeFor($created['secret']));

        // 30 seconds later, the next code is a different time step.
        Clock::freeze('2026-09-14 10:00:35');

        self::assertTrue(AdminAuth::verifyTotp($created['id'], $this->codeFor($created['secret'])));
    }

    public function testARecoveryCodeWorksOnce(): void
    {
        $created = AdminAuth::create('admin', self::PASSWORD);
        $code = $created['recovery_codes'][0];

        self::assertTrue(AdminAuth::verifyRecoveryCode($created['id'], $code));
        self::assertFalse(AdminAuth::verifyRecoveryCode($created['id'], $code), 'single use');
        self::assertSame(9, AdminAuth::countUnusedRecoveryCodes($created['id']));
    }

    public function testARecoveryCodeIsAcceptedRegardlessOfSpacingAndCase(): void
    {
        $created = AdminAuth::create('admin', self::PASSWORD);
        $code = $created['recovery_codes'][0];

        self::assertTrue(AdminAuth::verifyRecoveryCode(
            $created['id'],
            strtolower(str_replace('-', ' ', $code))
        ));
    }

    public function testAnUnknownRecoveryCodeIsRefused(): void
    {
        $created = AdminAuth::create('admin', self::PASSWORD);

        self::assertFalse(AdminAuth::verifyRecoveryCode($created['id'], 'ZZZZZ-ZZZZZ'));
    }

    public function testRegeneratingRecoveryCodesReplacesTheOldSet(): void
    {
        $created = AdminAuth::create('admin', self::PASSWORD);
        $old = $created['recovery_codes'][0];

        $new = AdminAuth::regenerateRecoveryCodes($created['id']);

        self::assertCount(10, $new);
        self::assertFalse(AdminAuth::verifyRecoveryCode($created['id'], $old));
        self::assertTrue(AdminAuth::verifyRecoveryCode($created['id'], $new[0]));
    }

    public function testResettingTheAuthenticatorIssuesANewSecret(): void
    {
        $created = AdminAuth::create('admin', self::PASSWORD);

        $reset = AdminAuth::resetTotp($created['id'], 'admin');

        self::assertNotSame($created['secret'], $reset['secret']);
        Clock::freeze('2026-09-14 10:00:00');
        self::assertFalse(AdminAuth::verifyTotp($created['id'], $this->codeFor($created['secret'])));
        self::assertTrue(AdminAuth::verifyTotp($created['id'], $this->codeFor($reset['secret'])));
    }

    public function testChangingThePasswordInvalidatesTheOldOne(): void
    {
        $created = AdminAuth::create('admin', self::PASSWORD);

        AdminAuth::changePassword($created['id'], 'another long enough password');

        self::assertNull(AdminAuth::verifyPassword('admin', self::PASSWORD));
        self::assertNotNull(AdminAuth::verifyPassword('admin', 'another long enough password'));
    }

    private function codeFor(string $secret): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setPeriod(30);
        $totp->setDigits(6);
        $totp->setDigest('sha1');

        return $totp->at(Clock::now()->getTimestamp());
    }
}
