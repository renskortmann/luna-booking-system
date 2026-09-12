<?php

declare(strict_types=1);

namespace Luna;

/**
 * Session handling with the lifetime and rebinding rules applied in one place.
 *
 * Cookie parameters are set in Bootstrap before anything starts a session, so
 * by the time start() runs the cookie is already Secure, HttpOnly and Lax.
 */
final class Session
{
    private const CREATED  = '_created_at';
    private const SEEN     = '_last_seen_at';
    private const AGENT    = '_agent_hash';
    private const FLASHES  = '_flashes';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $now = time();
        $_SESSION[self::CREATED] ??= $now;
        $_SESSION[self::SEEN] = $now;
        $_SESSION[self::AGENT] ??= self::agentHash();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();

        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        self::start();

        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    /**
     * New session id, same contents. Called whenever the privilege level
     * changes, so a fixated id cannot survive a login.
     */
    public static function regenerate(): void
    {
        self::start();

        // Sending a new session cookie needs headers; in a CLI context (tests,
        // cron) there are none, and the id does not matter there.
        if (!headers_sent()) {
            session_regenerate_id(true);
        }

        $_SESSION[self::CREATED] = time();
    }

    public static function destroy(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies') && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    /**
     * True while the session is still valid. Both limits are enforced: idle
     * time since the previous request, and total age since sign-in.
     *
     * The session is also bound to the browser's user agent. The client IP is
     * deliberately *not* part of the binding - campus wifi, eduroam and VPN all
     * change it mid-session, which would log people out at random.
     */
    public static function isAlive(int $idleMinutes, int $absoluteMinutes): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (($_SESSION[self::AGENT] ?? null) !== self::agentHash()) {
            return false;
        }

        $now = time();
        $lastSeen = (int) ($_SESSION[self::SEEN] ?? 0);
        $created = (int) ($_SESSION[self::CREATED] ?? 0);

        if ($lastSeen > 0 && $now - $lastSeen > $idleMinutes * 60) {
            return false;
        }

        if ($created > 0 && $now - $created > $absoluteMinutes * 60) {
            return false;
        }

        $_SESSION[self::SEEN] = $now;

        return true;
    }

    /** Queue a one-shot message for the next page render. */
    public static function flash(string $type, string $message): void
    {
        self::start();
        $_SESSION[self::FLASHES][] = ['type' => $type, 'message' => $message];
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    public static function takeFlashes(): array
    {
        self::start();
        $flashes = $_SESSION[self::FLASHES] ?? [];
        unset($_SESSION[self::FLASHES]);

        return is_array($flashes) ? $flashes : [];
    }

    private static function agentHash(): string
    {
        return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }
}
