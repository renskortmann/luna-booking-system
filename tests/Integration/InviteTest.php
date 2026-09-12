<?php

declare(strict_types=1);

namespace Luna\Tests\Integration;

use Luna\Clock;
use Luna\Db;
use Luna\Invite;
use Luna\Users;

/**
 * The single-use links that hand out access. This is how every account is
 * created, so the "once, and only for a while" part has to be exact.
 */
final class InviteTest extends DatabaseTestCase
{
    public function testAFreshTokenResolvesToItsUser(): void
    {
        $user = Users::create('alice');
        $token = Invite::issue($user->id);

        $invite = Invite::resolve($token);

        self::assertNotNull($invite);
        self::assertSame($user->id, (int) $invite['user_id']);
        self::assertSame('setup', $invite['purpose']);
    }

    public function testOnlyAHashOfTheTokenIsStored(): void
    {
        $user = Users::create('alice');
        $token = Invite::issue($user->id);

        $stored = (string) Db::get()->value('SELECT token_hash FROM user_invites');

        self::assertNotSame($token, $stored);
        self::assertSame(hash('sha256', $token), $stored);
    }

    public function testATokenWorksOnceOnly(): void
    {
        $user = Users::create('alice');
        $token = Invite::issue($user->id);

        $invite = Invite::resolve($token);
        Invite::consume((int) $invite['id']);

        self::assertNull(Invite::resolve($token), 'a spent link must not work again');
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $user = Users::create('alice');
        $token = Invite::issue($user->id);

        // Eight days later, with a seven-day lifetime.
        Clock::freeze(Clock::now()->modify('+8 days'));

        self::assertNull(Invite::resolve($token));
    }

    public function testIssuingANewLinkInvalidatesTheOldOne(): void
    {
        $user = Users::create('alice');
        $first = Invite::issue($user->id);
        $second = Invite::issue($user->id, Invite::PURPOSE_RESET);

        self::assertNull(Invite::resolve($first), 'the earlier link stops working');
        self::assertNotNull(Invite::resolve($second));
        self::assertSame(1, (int) Db::get()->value('SELECT COUNT(*) FROM user_invites'));
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        Users::create('alice');

        self::assertNull(Invite::resolve('not-a-real-token'));
        self::assertNull(Invite::resolve(''));
        self::assertNull(Invite::resolve(str_repeat('x', 500)));
    }

    public function testTheTokenItselfIsNeverWrittenToTheAuditLog(): void
    {
        $user = Users::create('alice');
        $token = Invite::issue($user->id);

        $details = (string) Db::get()->value(
            'SELECT COALESCE(details, "") FROM audit_log WHERE action = ?', ['invite_issued']
        );

        self::assertStringNotContainsString($token, $details);
    }

    public function testDeletingAUserTakesTheirInvitesWithThem(): void
    {
        $user = Users::create('alice');
        Invite::issue($user->id);

        Users::delete($user->id);

        self::assertSame(0, (int) Db::get()->value('SELECT COUNT(*) FROM user_invites'));
    }
}
