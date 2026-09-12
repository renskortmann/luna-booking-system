<?php

declare(strict_types=1);

namespace Luna;

use RuntimeException;

/**
 * Read-only access to app/config.php, addressed with dot notation.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $data = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException(
                'Configuration file not found: ' . $path
                . ' - copy app/config.example.php to app/config.php and fill it in.'
            );
        }

        $data = require $path;

        if (!is_array($data)) {
            throw new RuntimeException('Configuration file must return an array: ' . $path);
        }

        self::$data = $data;
    }

    /**
     * Replace the whole configuration. Used by tests; not used at runtime.
     *
     * @param array<string, mixed> $data
     */
    public static function set(array $data): void
    {
        self::$data = $data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$data;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Like get(), but refuses to continue when the value is missing or blank.
     * Use for anything whose absence is a deployment mistake rather than a
     * choice, such as the database password or the app key.
     */
    public static function mustGet(string $key): mixed
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            throw new RuntimeException(
                'Missing required configuration value "' . $key . '" in app/config.php.'
            );
        }

        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        return is_bool($value) ? $value : (bool) $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** Base URL with any trailing slash removed. */
    public static function baseUrl(): string
    {
        return rtrim(self::string('app.base_url'), '/');
    }

    /** Path prefix the app is mounted under, e.g. "" or "/luna". */
    public static function basePath(): string
    {
        $path = (string) parse_url(self::baseUrl(), PHP_URL_PATH);

        return rtrim($path, '/');
    }
}
