<?php

declare(strict_types=1);

namespace Luna;

use ErrorException;
use Luna\Http\HttpException;
use Luna\Http\Request;
use Luna\Http\Response;
use Throwable;

/**
 * Everything that must happen before a request is handled, and the single
 * place where an unhandled error turns into a response.
 */
final class Bootstrap
{
    public static function init(?string $configPath = null): void
    {
        Config::load($configPath ?? dirname(__DIR__) . '/config.php');

        // Storage and all internal arithmetic are UTC; only the display layer
        // knows about Europe/Amsterdam.
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');

        self::configureErrorReporting();
        self::configureSessionCookie();

        Db::init((array) Config::get('db', []));
    }

    /**
     * Dispatch the request and render whatever comes back - including errors.
     */
    public static function run(Router $router, Request $request): void
    {
        // Cross-cutting concerns - the audit log, the login throttle - read the
        // client address from here rather than being handed the request.
        Context::setRequest($request);

        try {
            if ($redirect = self::enforceHttps($request)) {
                self::emit($redirect, $request);

                return;
            }

            $response = $router->dispatch($request);
        } catch (HttpException $e) {
            $response = self::renderHttpError($e, $request);
        } catch (Throwable $e) {
            error_log('[luna] unhandled: ' . $e::class . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());

            $response = self::renderServerError($e, $request);
        }

        self::emit($response, $request);
    }

    private static function emit(Response $response, Request $request): void
    {
        self::sendSecurityHeaders($request);
        $response->send();
    }

    public static function isHttps(Request $request): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        // Only trusted when a proxy header is explicitly configured.
        if (Config::get('app.trusted_proxy_header')) {
            return strtolower($request->header('x-forwarded-proto') ?? '') === 'https';
        }

        return false;
    }

    private static function enforceHttps(Request $request): ?Response
    {
        if (!Config::bool('app.require_https', true) || self::isHttps($request)) {
            return null;
        }

        // Send the visitor to the canonical HTTPS base URL rather than
        // rewriting the host we were reached on.
        $target = Config::baseUrl() . ($request->path === '/' ? '/' : $request->path);

        return Response::redirectTo($target, 301);
    }

    private static function sendSecurityHeaders(Request $request): void
    {
        if (headers_sent()) {
            return;
        }

        // Every asset is served from this origin - nothing is loaded from a CDN -
        // so the policy can stay at 'self'. 'unsafe-inline' is needed for
        // style-src only because FullCalendar positions events with inline
        // style attributes; no inline <script> is used anywhere.
        header("Content-Security-Policy: default-src 'self'; base-uri 'none'; "
            . "object-src 'none'; frame-ancestors 'none'; form-action 'self'; "
            . "img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        header_remove('X-Powered-By');

        if (self::isHttps($request)) {
            header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
        }
    }

    private static function configureErrorReporting(): void
    {
        $debug = Config::bool('app.debug');

        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        // Promote warnings and notices to exceptions, so a silent failure in a
        // query or a date calculation cannot pass unnoticed.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    private static function configureSessionCookie(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $basePath = Config::basePath();

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $basePath === '' ? '/' : $basePath . '/',
            'secure'   => Config::bool('app.require_https', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('luna_session');

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
    }

    private static function renderHttpError(HttpException $e, Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => $e->userMessage()], $e->status);
        }

        // A browser that is simply not signed in wants the sign-in page, not an
        // error page. The API still gets its 401, which is what its caller can
        // actually act on.
        if ($e->status === 401) {
            return Response::redirect('/login');
        }

        // Likewise for the administration pages when nobody is signed in at
        // all. Someone signed in as a lab member does get the 403: for them it
        // is a real answer, not a missing session.
        if ($e->status === 403
            && str_starts_with($request->path, '/admin')
            && !Auth::isAdmin()
            && Auth::user() === null) {
            return Response::redirect('/admin/login');
        }

        return self::errorPage($e->status, $e->userMessage());
    }

    private static function renderServerError(Throwable $e, Request $request): Response
    {
        $message = Config::bool('app.debug')
            ? $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
            : 'Something went wrong. Please try again, and tell the lab administrator if it keeps happening.';

        if ($request->wantsJson()) {
            return Response::json(['error' => $message], 500);
        }

        return self::errorPage(500, $message);
    }

    private static function errorPage(int $status, string $message): Response
    {
        try {
            return View::page('error', [
                'title'   => 'Error ' . $status,
                'status'  => $status,
                'message' => $message,
            ], $status);
        } catch (Throwable) {
            // The templates themselves are broken; fall back to bare HTML so the
            // visitor still gets a readable page.
            return Response::html(
                '<!doctype html><meta charset="utf-8"><title>Error ' . $status . '</title>'
                . '<h1>Error ' . $status . '</h1><p>' . e($message) . '</p>',
                $status
            );
        }
    }
}
