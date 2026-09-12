<?php

declare(strict_types=1);

namespace Luna\Http;

use RuntimeException;

/**
 * An error that maps directly onto a status code. Thrown from anywhere;
 * rendered once, centrally, by the front controller.
 */
class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly ?string $publicMessage = null,
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status));
    }

    /** Message safe to show a visitor. Never leaks internal detail. */
    public function userMessage(): string
    {
        return $this->publicMessage ?? self::defaultMessage($this->status);
    }

    public static function badRequest(string $publicMessage = 'That request could not be processed.'): self
    {
        return new self(400, 'Bad request', $publicMessage);
    }

    public static function unauthorized(string $publicMessage = 'Please sign in to continue.'): self
    {
        return new self(401, 'Unauthenticated', $publicMessage);
    }

    public static function forbidden(string $publicMessage = 'You are not allowed to do that.'): self
    {
        return new self(403, 'Forbidden', $publicMessage);
    }

    public static function notFound(string $publicMessage = 'Page not found.'): self
    {
        return new self(404, 'Not found', $publicMessage);
    }

    public static function methodNotAllowed(): self
    {
        return new self(405, 'Method not allowed', 'That method is not allowed here.');
    }

    public static function conflict(string $publicMessage): self
    {
        return new self(409, 'Conflict', $publicMessage);
    }

    public static function tooManyRequests(string $publicMessage): self
    {
        return new self(429, 'Too many requests', $publicMessage);
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'That request could not be processed.',
            401 => 'Please sign in to continue.',
            403 => 'You are not allowed to do that.',
            404 => 'Page not found.',
            405 => 'That method is not allowed here.',
            409 => 'That conflicts with something that already exists.',
            429 => 'Too many attempts. Please wait and try again.',
            default => 'Something went wrong.',
        };
    }
}
