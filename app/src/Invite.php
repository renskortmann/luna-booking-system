<?php

declare(strict_types=1);

namespace Macrolab;

/**
 * Single-use links that let a lab member set their own password. This is how
 * accounts are handed out, because the system deliberately sends no email: the
 * admin copies the link and passes it on however they like.
 *
 * Only a hash of the token is stored, so the database cannot be used to
 * generate a working link. The plaintext exists once, in the admin's clipboard.
 */
final class Invite
{
    public const PURPOSE_SETUP = 'setup';
    public const PURPOSE_RESET = 'reset';

    /**
     * Issue a link for a user, invalidating any outstanding one so that an old
     * link cannot be used after a new one has been handed out.
     *
     * @return string the plaintext token - shown once and never stored
     */
    public static function issue(int $userId, string $purpose = self::PURPOSE_SETUP): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $days = max(1, Config::int('auth.invite_ttl_days', 7));

        Db::get()->transaction(static function () use ($userId, $purpose, $token, $days): void {
            self::invalidateOutstanding($userId);

            Db::get()->insert('user_invites', [
                'user_id'    => $userId,
                'token_hash' => self::hash($token),
                'purpose'    => $purpose,
                'expires_at' => Clock::sql(Clock::now()->modify('+' . $days . ' days')),
                'created_at' => Clock::sql(),
            ]);
        });

        // The token itself is never logged: the audit trail records that a link
        // was issued, not what it was.
        Audit::log('invite_issued', 'user', $userId, ['purpose' => $purpose, 'ttl_days' => $days]);

        return $token;
    }

    public static function urlFor(string $token): string
    {
        return url('/setup/' . $token);
    }

    public static function invalidateOutstanding(int $userId): void
    {
        Db::get()->query(
            'DELETE FROM user_invites WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );
    }

    /**
     * The user this token belongs to, or null when the token is unknown,
     * already used, or expired - the three cases are indistinguishable to the
     * visitor on purpose.
     */
    public static function resolve(string $token): ?array
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        $row = Db::get()->one(
            'SELECT id, user_id, purpose, expires_at
               FROM user_invites
              WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [self::hash($token), Clock::sql()]
        );

        return $row;
    }

    /** Mark a token used. Called in the same transaction as the password write. */
    public static function consume(int $inviteId): void
    {
        Db::get()->update('user_invites', ['used_at' => Clock::sql()], 'id = ? AND used_at IS NULL', [$inviteId]);
    }

    /**
     * Tokens carry 256 bits of entropy, so a single fast hash is the right
     * choice here: there is nothing to brute-force, and a slow KDF would only
     * add latency to every click.
     */
    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
