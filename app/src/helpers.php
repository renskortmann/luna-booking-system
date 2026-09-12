<?php

declare(strict_types=1);

/**
 * Helpers available to every template. Loaded through Composer's "files"
 * autoloader, so they exist before any view is rendered.
 */

if (!function_exists('e')) {
    /**
     * Escape for HTML text and quoted attribute contexts. Every value
     * interpolated into a template goes through this, without exception.
     */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (!is_scalar($value)) {
            $value = is_object($value) && method_exists($value, '__toString')
                ? (string) $value
                : '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /** Absolute URL for an app-relative path, honouring a subdirectory mount. */
    function url(string $path = '/'): string
    {
        return \Luna\Config::baseUrl() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('path')) {
    /** Root-relative URL for an app-relative path (for href/action attributes). */
    function path(string $path = '/'): string
    {
        $base = \Luna\Config::basePath();

        return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
    }
}
