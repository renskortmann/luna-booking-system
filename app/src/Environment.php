<?php

declare(strict_types=1);

namespace Macrolab;

use Throwable;

/**
 * What the hosting actually offers. Shown by the installer, because on managed
 * hosting the operator often cannot run `php -m` to find out.
 */
final class Environment
{
    /**
     * @return list<array{label: string, ok: bool, detail: string, fatal: bool}>
     */
    public static function checks(): array
    {
        $checks = [];

        $checks[] = self::check(
            'PHP version',
            version_compare(PHP_VERSION, '8.2.0', '>='),
            PHP_VERSION . (version_compare(PHP_VERSION, '8.2.0', '>=') ? '' : ' - 8.2 or newer is required'),
            fatal: true,
        );

        foreach (['pdo_mysql', 'mbstring', 'openssl', 'json'] as $extension) {
            $checks[] = self::check(
                'Extension ' . $extension,
                extension_loaded($extension),
                extension_loaded($extension) ? 'loaded' : 'missing - the application cannot run without it',
                fatal: true,
            );
        }

        // Not fatal: there are working fallbacks, but the operator should know
        // which one they ended up with.
        $checks[] = self::check(
            'Extension sodium',
            extension_loaded('sodium'),
            extension_loaded('sodium')
                ? 'loaded - Argon2id password hashing and libsodium encryption'
                : 'missing - falling back to bcrypt and AES-256-GCM, which is still sound',
        );

        $checks[] = self::check(
            'Password hashing',
            true,
            Password::algorithm(),
        );

        $checks[] = self::check(
            'Database connection',
            self::databaseReachable(),
            self::databaseReachable()
                ? 'connected to "' . Config::string('db.name') . '"'
                : 'cannot connect - check the db block in app/config.php',
            fatal: true,
        );

        $key = (string) Config::get('app.key', '');
        $keyOk = $key !== '' && strlen((string) base64_decode($key, true)) === 32;
        $checks[] = self::check(
            'Application key',
            $keyOk,
            $keyOk ? 'set' : 'missing or malformed - see app.key in app/config.php',
            fatal: true,
        );

        $https = Config::bool('app.require_https', true);
        $checks[] = self::check(
            'HTTPS required',
            $https,
            $https ? 'yes' : 'no - only acceptable for local development',
        );

        $baseUrl = Config::baseUrl();
        $checks[] = self::check(
            'Base URL',
            str_starts_with($baseUrl, 'https://'),
            $baseUrl === '' ? 'not set' : $baseUrl,
        );

        // Whether app/ sits outside the document root is the difference between
        // "secrets are unreachable" and "secrets depend on .htaccess".
        $appDir = dirname(__DIR__);
        $docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $outside = $docRoot !== '' && !str_starts_with($appDir . '/', $docRoot . '/');
        $checks[] = self::check(
            'Application directory',
            $outside,
            $outside
                ? 'outside the document root - secrets are not web-reachable'
                : 'inside the document root - protection depends on .htaccess, so verify it '
                  . '(see README.md) before trusting this installation',
        );

        return $checks;
    }

    public static function anyFatal(): bool
    {
        foreach (self::checks() as $check) {
            if ($check['fatal'] && !$check['ok']) {
                return true;
            }
        }

        return false;
    }

    private static function databaseReachable(): bool
    {
        static $reachable = null;

        if ($reachable !== null) {
            return $reachable;
        }

        try {
            Db::get()->value('SELECT 1');

            return $reachable = true;
        } catch (Throwable) {
            return $reachable = false;
        }
    }

    /**
     * @return array{label: string, ok: bool, detail: string, fatal: bool}
     */
    private static function check(string $label, bool $ok, string $detail, bool $fatal = false): array
    {
        return ['label' => $label, 'ok' => $ok, 'detail' => $detail, 'fatal' => $fatal];
    }
}
