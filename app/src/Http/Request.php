<?php

declare(strict_types=1);

namespace Macrolab\Http;

use Macrolab\Config;

/**
 * The incoming request, normalised. `path` is always relative to the directory
 * the app is mounted in, so the same routes work at https://host/ and at
 * https://host/macrolab/.
 */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $headers = [],
        public readonly ?string $remoteAddr = null,
        public readonly string $rawBody = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        $base = Config::basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim($path, '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $rawBody = '';
        $post = $_POST;

        // A JSON body arrives as a raw stream, not in $_POST.
        if (str_contains($headers['content-type'] ?? '', 'application/json')) {
            $rawBody = (string) file_get_contents('php://input');
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $post = $decoded;
            }
        }

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            post: $post,
            headers: $headers,
            remoteAddr: self::resolveRemoteAddr($headers),
            rawBody: $rawBody,
        );
    }

    /**
     * REMOTE_ADDR unless a trusted proxy header is explicitly configured. We do
     * not honour X-Forwarded-For by default: an attacker could otherwise spoof
     * it and slip past the per-IP login throttle.
     *
     * @param array<string, string> $headers
     */
    private static function resolveRemoteAddr(array $headers): ?string
    {
        $header = Config::get('app.trusted_proxy_header');

        if (is_string($header) && $header !== '') {
            $value = $headers[strtolower($header)] ?? '';
            // A forwarding chain lists the original client first.
            $first = trim(explode(',', $value)[0] ?? '');
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        $addr = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($addr) && $addr !== '' ? $addr : null;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function post(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function userAgent(): string
    {
        return substr($this->header('user-agent') ?? '', 0, 255);
    }

    /** Packed binary form of the client address, for the VARBINARY(16) columns. */
    public function ipBinary(): ?string
    {
        if ($this->remoteAddr === null) {
            return null;
        }

        $packed = @inet_pton($this->remoteAddr);

        return $packed === false ? null : $packed;
    }

    /** True when the caller is the JSON API rather than a browser form. */
    public function wantsJson(): bool
    {
        return str_contains($this->header('accept') ?? '', 'application/json')
            || str_contains($this->header('content-type') ?? '', 'application/json')
            || str_starts_with($this->path, '/api/');
    }
}
