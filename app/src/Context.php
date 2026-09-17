<?php

declare(strict_types=1);

namespace Macrolab;

use Macrolab\Http\Request;

/**
 * The request currently being handled. Exists so that cross-cutting concerns -
 * the audit log, the login throttle - can record the client address without
 * every call site having to pass the request down to them.
 */
final class Context
{
    private static ?Request $request = null;

    public static function setRequest(?Request $request): void
    {
        self::$request = $request;
    }

    public static function request(): ?Request
    {
        return self::$request;
    }
}
