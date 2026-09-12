<?php

declare(strict_types=1);

namespace Luna;

/**
 * Password hashing and the password policy, in one place so that the user
 * setup page, the change-password page and the admin account all agree.
 */
final class Password
{
    /** A hash of a value nobody can supply, for equalising failure timing. */
    private static ?string $dummyHash = null;

    public static function hash(string $plain): string
    {
        if (defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
            return password_hash($plain, PASSWORD_ARGON2ID, [
                'memory_cost' => 64 * 1024, // 64 MiB
                'time_cost'   => 3,
                'threads'     => 1,
            ]);
        }

        // Hosting without libsodium/argon2 support. Still a sound choice; the
        // README tells the operator which one they got.
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)
            ? 'argon2id'
            : 'bcrypt';
    }

    public static function verify(string $plain, string $hash): bool
    {
        return $hash !== '' && password_verify($plain, $hash);
    }

    /**
     * Spend the same effort as a real verification would, so that an unknown
     * netID cannot be distinguished from a wrong password by response time.
     */
    public static function dummyVerify(string $plain = ''): void
    {
        self::$dummyHash ??= self::hash(bin2hex(random_bytes(16)));
        password_verify($plain, self::$dummyHash);
    }

    /** True when the stored hash was made with weaker parameters than we use now. */
    public static function needsRehash(string $hash): bool
    {
        return self::algorithm() === 'argon2id'
            ? password_needs_rehash($hash, PASSWORD_ARGON2ID, ['memory_cost' => 64 * 1024, 'time_cost' => 3, 'threads' => 1])
            : password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * The policy. Returns the reason the password is unacceptable, or null when
     * it is fine.
     *
     * Length is the requirement that actually matters, so there are no
     * character-class rules: they push people towards "Summer2026!" and no
     * further. A short denylist catches the handful of passwords that get
     * tried first, and the netID itself is refused.
     */
    public static function policyError(string $plain, ?string $netid = null): ?string
    {
        $minimum = max(8, Config::int('auth.password_min_length', 12));
        $length = mb_strlen($plain);

        if ($length < $minimum) {
            return 'Please use at least ' . $minimum . ' characters.';
        }

        if ($length > 4096) {
            return 'That password is unreasonably long.';
        }

        if (trim($plain) === '') {
            return 'Please enter a password.';
        }

        $lower = mb_strtolower($plain);

        if ($netid !== null && $netid !== '' && str_contains($lower, mb_strtolower($netid))) {
            return 'Please do not include your netID in your password.';
        }

        if (in_array($lower, self::denylist(), true)) {
            return 'That password is too common. Please choose something else.';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function denylist(): array
    {
        static $list = null;

        if ($list !== null) {
            return $list;
        }

        $file = dirname(__DIR__) . '/data/common-passwords.txt';
        $list = [];

        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && !str_starts_with($line, '#')) {
                    $list[] = mb_strtolower($line);
                }
            }
        }

        return $list;
    }
}
