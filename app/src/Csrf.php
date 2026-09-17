<?php

declare(strict_types=1);

namespace Macrolab;

use Macrolab\Http\HttpException;
use Macrolab\Http\Request;

/**
 * One token per session, required on every state-changing request - HTML forms
 * through a hidden field, the JSON API through the X-CSRF-Token header.
 */
final class Csrf
{
    private const KEY = '_csrf_token';
    public const FIELD = '_token';
    public const HEADER = 'x-csrf-token';

    public static function token(): string
    {
        $token = Session::get(self::KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::KEY, $token);
        }

        return $token;
    }

    /** Hidden input for a form. */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . e(self::token()) . '">';
    }

    /**
     * Throws unless the request carries the session's token. Call this at the
     * top of every POST handler; there is no global middleware to forget.
     */
    public static function verify(Request $request): void
    {
        $expected = Session::get(self::KEY);
        $provided = $request->header(self::HEADER) ?? $request->post(self::FIELD) ?? '';

        if (!is_string($expected) || $expected === '' || !hash_equals($expected, (string) $provided)) {
            throw HttpException::badRequest(
                'Your session expired or the form was stale. Reload the page and try again.'
            );
        }
    }

    /** Drop the token, so a new one is issued for the next session. */
    public static function rotate(): void
    {
        Session::forget(self::KEY);
    }
}
