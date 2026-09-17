<?php

declare(strict_types=1);

namespace Macrolab;

/**
 * Login throttling, shared by the user and admin login paths.
 *
 * Attempts are counted per identifier and per client address independently, so
 * neither spraying one account from many hosts nor many accounts from one host
 * slips through.
 */
final class RateLimit
{
    public static function record(string $identifier, bool $success): void
    {
        Db::get()->insert('login_attempts', [
            'identifier' => substr(strtolower($identifier), 0, 128),
            'ip'         => Context::request()?->ipBinary(),
            'success'    => $success ? 1 : 0,
            'created_at' => Clock::sql(),
        ]);
    }

    /**
     * Seconds the caller must wait, or 0 when they may try now.
     */
    public static function retryAfter(string $identifier): int
    {
        $maxFailures = Config::int('auth.lockout.max_failures', 10);
        $windowMinutes = Config::int('auth.lockout.window_minutes', 15);
        $lockMinutes = Config::int('auth.lockout.lock_minutes', 15);

        $since = Clock::sql(Clock::now()->modify('-' . $windowMinutes . ' minutes'));
        $ip = Context::request()?->ipBinary();

        $db = Db::get();

        $byIdentifier = $db->one(
            'SELECT COUNT(*) AS failures, MAX(created_at) AS last_at
               FROM login_attempts
              WHERE identifier = ? AND success = 0 AND created_at >= ?',
            [substr(strtolower($identifier), 0, 128), $since]
        );

        $worst = self::lockRemaining($byIdentifier, $maxFailures, $lockMinutes);

        if ($ip !== null) {
            $byIp = $db->one(
                'SELECT COUNT(*) AS failures, MAX(created_at) AS last_at
                   FROM login_attempts
                  WHERE ip = ? AND success = 0 AND created_at >= ?',
                [$ip, $since]
            );
            // An address may be shared by a whole lab behind NAT, so it gets a
            // more generous budget than a single account does.
            $worst = max($worst, self::lockRemaining($byIp, $maxFailures * 3, $lockMinutes));
        }

        return $worst;
    }

    public static function assertAllowed(string $identifier): void
    {
        $wait = self::retryAfter($identifier);

        if ($wait > 0) {
            Audit::log('login_throttled', 'login', null, ['identifier' => $identifier, 'retry_after' => $wait]);

            throw Http\HttpException::tooManyRequests(
                'Too many failed attempts. Try again in ' . (int) ceil($wait / 60) . ' minute(s).'
            );
        }
    }

    /** Forget the failures for an identifier, after a successful sign-in. */
    public static function clear(string $identifier): void
    {
        Db::get()->query(
            'DELETE FROM login_attempts WHERE identifier = ? AND success = 0',
            [substr(strtolower($identifier), 0, 128)]
        );
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function lockRemaining(?array $row, int $maxFailures, int $lockMinutes): int
    {
        $failures = (int) ($row['failures'] ?? 0);

        if ($failures < $maxFailures || empty($row['last_at'])) {
            return 0;
        }

        $unlockAt = Clock::fromSql((string) $row['last_at'])->getTimestamp() + $lockMinutes * 60;

        return max(0, $unlockAt - Clock::now()->getTimestamp());
    }
}
