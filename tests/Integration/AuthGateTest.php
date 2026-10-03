<?php

declare(strict_types=1);

namespace Macrolab\Tests\Integration;

use Macrolab\Auth;
use Macrolab\Auth\AccessDeniedException;
use Macrolab\Auth\Identity;
use Macrolab\Config;
use Macrolab\Db;
use Macrolab\Settings;
use Macrolab\Users;

/**
 * The allowlist gate in Auth::signIn().
 *
 * These tests hand an Identity straight to Auth, bypassing any provider. That
 * is the point: the gate must hold whoever vouched for the identity, which is
 * what will keep it holding when TU Delft SSO is added as a second provider.
 */
final class AuthGateTest extends DatabaseTestCase
{
    public function testAnApprovedNetidIsLetIn(): void
    {
        Users::create('alice');

        $user = Auth::signIn(new Identity(netid: 'alice', method: 'test'));

        self::assertSame('alice', $user->netid);
        self::assertSame('alice', Auth::user()?->netid);
    }

    public function testANetidThatIsNotOnTheAllowlistIsRefused(): void
    {
        try {
            Auth::signIn(new Identity(netid: 'stranger', method: 'test'));
            self::fail('an unlisted netID must not be let in');
        } catch (AccessDeniedException $e) {
            self::assertSame('not_allowlisted', $e->reason);
        }

        self::assertNull(Auth::user());
        self::assertSame(1, $this->auditCount('login_denied_not_allowlisted'));
    }

    public function testASuspendedNetidIsRefused(): void
    {
        $user = Users::create('bob');
        Users::setStatus($user->id, 'suspended');

        try {
            Auth::signIn(new Identity(netid: 'bob', method: 'test'));
            self::fail('a suspended account must not be let in');
        } catch (AccessDeniedException $e) {
            self::assertSame('suspended', $e->reason);
        }

        self::assertNull(Auth::user());
        self::assertSame(1, $this->auditCount('login_denied_suspended'));
    }

    public function testSuspendingSomeoneEndsTheirSessionOnTheNextRequest(): void
    {
        $user = Users::create('carol');
        Auth::signIn(new Identity(netid: 'carol', method: 'test'));
        self::assertNotNull(Auth::user());

        // The admin suspends them while they are signed in.
        Users::setStatus($user->id, 'suspended');
        Auth::resetCache();

        self::assertNull(Auth::user(), 'the allowlist is re-checked on every request');
    }

    public function testNetidCaseDoesNotCreateASecondAccount(): void
    {
        Users::create('Dave');

        $user = Auth::signIn(new Identity(netid: 'DAVE', method: 'test'));

        self::assertSame('dave', $user->netid);
        self::assertSame(1, (int) Db::get()->value('SELECT COUNT(*) FROM users'));
    }

    public function testDetailsFromTheProviderFillInBlanksButDoNotOverwrite(): void
    {
        $created = Users::create('erin', 'Erin Original');

        Auth::signIn(new Identity(
            netid: 'erin',
            email: 'erin@tudelft.nl',
            displayName: 'Erin From SSO',
            samlNameId: 'opaque-name-id',
            method: 'test',
        ));

        $user = Users::findById($created->id);

        self::assertSame('Erin Original', $user?->displayName, 'an existing name is left alone');
        self::assertSame('erin@tudelft.nl', $user?->email, 'a missing email is filled in');
        self::assertSame('opaque-name-id', $user?->samlNameId);
        self::assertNotNull($user?->firstLoginAt);
    }

    public function testSigningInRecordsAnAuditEntry(): void
    {
        Users::create('frank');
        Auth::signIn(new Identity(netid: 'frank', method: 'test'));

        self::assertSame(1, $this->auditCount('login'));
    }

    public function testLogoutClearsTheSession(): void
    {
        Users::create('grace');
        Auth::signIn(new Identity(netid: 'grace', method: 'test'));

        Auth::logout();

        self::assertNull(Auth::user());
        self::assertFalse(Auth::isAdmin());
    }

    public function testAnIdleMemberSessionEnds(): void
    {
        Users::create('ivy');
        Auth::signIn(new Identity(netid: 'ivy', method: 'test'));

        $_SESSION['_last_seen_at'] = time() - (Config::int('auth.user_session_idle_minutes') + 1) * 60;
        Auth::resetCache();

        self::assertNull(Auth::user(), 'idle longer than the limit: signed out');
    }

    public function testAnActiveMemberSessionContinues(): void
    {
        Users::create('jay');
        Auth::signIn(new Identity(netid: 'jay', method: 'test'));

        $_SESSION['_last_seen_at'] = time() - (Config::int('auth.user_session_idle_minutes') - 1) * 60;
        Auth::resetCache();

        self::assertSame('jay', Auth::user()?->netid, 'idle shorter than the limit: still signed in');
        self::assertGreaterThan(time() - 5, $_SESSION['_last_seen_at'], 'and the activity is recorded');
    }

    public function testAnIdleAdministratorSessionEnds(): void
    {
        Auth::completeAdminLogin(1, 'admin');
        self::assertTrue(Auth::isAdmin());

        $_SESSION['_last_seen_at'] = time() - (Config::int('auth.admin_session_idle_minutes') + 1) * 60;

        self::assertFalse(Auth::isAdmin(), 'idle longer than the limit: signed out');
    }

    /**
     * Stage 2 is not built, so a stored SSO mode must not switch off password
     * sign-in: that would lock out every lab member with nothing in its place.
     */
    public function testAStoredSsoModeIsIgnoredWhileSsoIsNotAvailable(): void
    {
        self::assertFalse(Settings::ssoAvailable(), 'this test assumes stage 2 is not built yet');

        foreach (['saml', 'both'] as $mode) {
            Settings::set('auth_mode', $mode);

            self::assertSame('local', Settings::authMode(), $mode . ' is ignored');
            self::assertTrue(Settings::localLoginEnabled(), 'password sign-in stays on under ' . $mode);
            self::assertFalse(Settings::samlLoginEnabled(), 'no SSO button under ' . $mode);
        }
    }

    private function auditCount(string $action): int
    {
        return (int) Db::get()->value('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
    }
}
