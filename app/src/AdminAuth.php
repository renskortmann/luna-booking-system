<?php

declare(strict_types=1);

namespace Luna;

use OTPHP\TOTP;
use RuntimeException;

/**
 * The single local administrator account: password plus a mandatory
 * time-based one-time code.
 *
 * This login is deliberately independent of the `auth_mode` setting, so the
 * administrator can always get in without TU Delft SSO - including while SSO
 * is broken, which is exactly when they need to.
 */
final class AdminAuth
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    /** Accept the previous and next step, for clock drift. */
    private const WINDOW_STEPS = 1;

    public static function exists(): bool
    {
        return (int) Db::get()->value('SELECT COUNT(*) FROM admin_account') > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findByUsername(string $username): ?array
    {
        return Db::get()->one(
            'SELECT * FROM admin_account WHERE username = ?',
            [strtolower(trim($username))]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        return Db::get()->one('SELECT * FROM admin_account WHERE id = ?', [$id]);
    }

    /**
     * Create the administrator. Refuses to run a second time: an installer that
     * can be replayed is an installer that can be used to take the system over.
     *
     * @return array{id: int, secret: string, uri: string, recovery_codes: list<string>}
     */
    public static function create(string $username, string $password): array
    {
        if (self::exists()) {
            throw new RuntimeException(
                'An administrator account already exists. Use the password change page, '
                . 'or remove the row from admin_account first.'
            );
        }

        $username = strtolower(trim($username));

        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $username)) {
            throw new RuntimeException('Username must be 3-64 characters: letters, digits, dot, dash or underscore.');
        }

        if ($error = Password::policyError($password)) {
            throw new RuntimeException($error);
        }

        $totp = TOTP::generate();
        $secret = $totp->getSecret();

        $id = Db::get()->insert('admin_account', [
            'username'            => $username,
            'password_hash'       => Password::hash($password),
            'totp_secret_enc'     => Crypto::encrypt($secret),
            'password_changed_at' => Clock::sql(),
            'created_at'          => Clock::sql(),
        ]);

        $codes = self::regenerateRecoveryCodes($id);

        Audit::log('admin_created', 'admin', $id, ['username' => $username],
            actorType: 'system', actorLabel: 'installer');

        return [
            'id'             => $id,
            'secret'         => $secret,
            'uri'            => self::provisioningUri($username, $secret),
            'recovery_codes' => $codes,
        ];
    }

    /**
     * Check the first factor. Returns the account row on success, null on
     * failure, always spending comparable time either way.
     *
     * @return array<string, mixed>|null
     */
    public static function verifyPassword(string $username, string $password): ?array
    {
        $username = strtolower(trim($username));
        $account = self::findByUsername($username);

        if ($account === null) {
            Password::dummyVerify($password);
            RateLimit::record('admin:' . $username, false);
            Audit::log('admin_login_failed', 'admin', null, ['username' => $username, 'stage' => 'password'],
                actorType: 'anonymous', actorLabel: 'admin:' . $username);

            return null;
        }

        if (!Password::verify($password, (string) $account['password_hash'])) {
            RateLimit::record('admin:' . $username, false);
            Audit::log('admin_login_failed', 'admin', (int) $account['id'],
                ['username' => $username, 'stage' => 'password'],
                actorType: 'anonymous', actorLabel: 'admin:' . $username);

            return null;
        }

        if (Password::needsRehash((string) $account['password_hash'])) {
            Db::get()->update('admin_account', ['password_hash' => Password::hash($password)],
                'id = ?', [(int) $account['id']]);
        }

        return $account;
    }

    /**
     * Check the second factor.
     *
     * A code is accepted at most once: the time step it belongs to is recorded,
     * and any code from that step or earlier is refused afterwards. Without
     * that, a code shoulder-surfed or captured in transit stays usable for its
     * full 30-second window.
     */
    public static function verifyTotp(int $adminId, string $code): bool
    {
        $account = self::findById($adminId);
        $code = preg_replace('/\D+/', '', $code) ?? '';

        if ($account === null || $code === '' || empty($account['totp_secret_enc'])) {
            return false;
        }

        $secret = Crypto::decrypt(self::blob($account['totp_secret_enc']));
        $totp = self::totp($secret);

        $now = Clock::now()->getTimestamp();
        $currentStep = intdiv($now, self::PERIOD);
        $lastUsed = $account['totp_last_counter'] !== null ? (int) $account['totp_last_counter'] : null;

        for ($offset = -self::WINDOW_STEPS; $offset <= self::WINDOW_STEPS; $offset++) {
            $step = $currentStep + $offset;

            if ($lastUsed !== null && $step <= $lastUsed) {
                continue; // already spent
            }

            if (hash_equals($totp->at($step * self::PERIOD), $code)) {
                Db::get()->update('admin_account', ['totp_last_counter' => $step], 'id = ?', [$adminId]);

                return true;
            }
        }

        RateLimit::record('admin:' . $account['username'], false);
        Audit::log('admin_login_failed', 'admin', $adminId,
            ['username' => $account['username'], 'stage' => 'totp'],
            actorType: 'anonymous', actorLabel: 'admin:' . $account['username']);

        return false;
    }

    /**
     * Spend a recovery code. For when the authenticator is lost - the way back
     * in that does not require a database edit.
     */
    public static function verifyRecoveryCode(int $adminId, string $code): bool
    {
        $normalised = self::normaliseRecoveryCode($code);

        if ($normalised === '') {
            return false;
        }

        $row = Db::get()->one(
            'SELECT id FROM admin_recovery_codes WHERE admin_id = ? AND code_hash = ? AND used_at IS NULL',
            [$adminId, self::hashRecoveryCode($normalised)]
        );

        if ($row === null) {
            Audit::log('admin_login_failed', 'admin', $adminId, ['stage' => 'recovery_code'],
                actorType: 'anonymous', actorLabel: 'admin');

            return false;
        }

        Db::get()->update('admin_recovery_codes', ['used_at' => Clock::sql()],
            'id = ? AND used_at IS NULL', [(int) $row['id']]);

        Audit::log('admin_recovery_code_used', 'admin', $adminId, [
            'remaining' => self::countUnusedRecoveryCodes($adminId),
        ], actorType: 'admin', actorLabel: 'admin');

        return true;
    }

    public static function countUnusedRecoveryCodes(int $adminId): int
    {
        return (int) Db::get()->value(
            'SELECT COUNT(*) FROM admin_recovery_codes WHERE admin_id = ? AND used_at IS NULL',
            [$adminId]
        );
    }

    /**
     * Replace the whole set of recovery codes. Shown once; only hashes are kept.
     *
     * The codes carry ~80 bits of entropy, so a single fast hash is the right
     * trade-off, as with invite tokens: there is nothing to brute-force.
     *
     * @return list<string>
     */
    public static function regenerateRecoveryCodes(int $adminId, int $count = 10): array
    {
        $codes = [];

        Db::get()->transaction(static function () use ($adminId, $count, &$codes): void {
            Db::get()->query('DELETE FROM admin_recovery_codes WHERE admin_id = ?', [$adminId]);

            for ($i = 0; $i < $count; $i++) {
                $code = self::formatRecoveryCode(random_bytes(10));
                $codes[] = $code;

                Db::get()->insert('admin_recovery_codes', [
                    'admin_id'   => $adminId,
                    'code_hash'  => self::hashRecoveryCode(self::normaliseRecoveryCode($code)),
                    'created_at' => Clock::sql(),
                ]);
            }
        });

        return $codes;
    }

    public static function changePassword(int $adminId, string $newPassword): void
    {
        if ($error = Password::policyError($newPassword)) {
            throw new RuntimeException($error);
        }

        Db::get()->update('admin_account', [
            'password_hash'       => Password::hash($newPassword),
            'password_changed_at' => Clock::sql(),
        ], 'id = ?', [$adminId]);

        Audit::log('admin_password_changed', 'admin', $adminId);
    }

    /** Re-enrol the authenticator app. Returns the new secret and its otpauth URI. */
    public static function resetTotp(int $adminId, string $username): array
    {
        $secret = TOTP::generate()->getSecret();

        Db::get()->update('admin_account', [
            'totp_secret_enc'   => Crypto::encrypt($secret),
            'totp_last_counter' => null,
            'totp_confirmed_at' => null,
        ], 'id = ?', [$adminId]);

        Audit::log('admin_totp_reset', 'admin', $adminId);

        return ['secret' => $secret, 'uri' => self::provisioningUri($username, $secret)];
    }

    public static function markTotpConfirmed(int $adminId): void
    {
        Db::get()->update('admin_account', ['totp_confirmed_at' => Clock::sql()], 'id = ?', [$adminId]);
    }

    /** otpauth:// URI for the enrolment QR code. */
    public static function provisioningUri(string $username, string $secret): string
    {
        $totp = self::totp($secret);
        $totp->setLabel($username);
        $totp->setIssuer(Config::string('app.name', 'LUNA OD6 Booking'));

        return $totp->getProvisioningUri();
    }

    private static function totp(string $secret): TOTP
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setPeriod(self::PERIOD);
        $totp->setDigits(self::DIGITS);
        $totp->setDigest('sha1'); // what every authenticator app implements

        return $totp;
    }

    /** BLOB columns come back as a stream on some drivers. */
    private static function blob(mixed $value): string
    {
        if (is_resource($value)) {
            return (string) stream_get_contents($value);
        }

        return (string) $value;
    }

    private static function formatRecoveryCode(string $entropy): string
    {
        // Crockford-style alphabet: no I, L, O or U, so codes can be read aloud
        // and typed without ambiguity.
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $code = '';

        foreach (str_split($entropy) as $byte) {
            $code .= $alphabet[ord($byte) % 32];
        }

        return implode('-', str_split($code, 5));
    }

    private static function normaliseRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $code) ?? '');
    }

    private static function hashRecoveryCode(string $normalised): string
    {
        return hash('sha256', $normalised);
    }
}
