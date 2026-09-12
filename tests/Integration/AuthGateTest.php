<?php

declare(strict_types=1);

namespace Luna\Tests\Integration;

use Luna\Auth;
use Luna\Auth\AccessDeniedException;
use Luna\Auth\Identity;
use Luna\Db;
use Luna\Users;

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

    private function auditCount(string $action): int
    {
        return (int) Db::get()->value('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
    }
}
