<?php

declare(strict_types=1);

namespace Luna\Auth;

use RuntimeException;

/**
 * The person proved who they are, but they are not allowed in: their netID is
 * not on the allowlist, or it has been suspended.
 *
 * Deliberately distinct from a failed credential check - this one is not the
 * user's mistake, and the message tells them what to do about it.
 */
final class AccessDeniedException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $netid,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notAllowlisted(string $netid): self
    {
        return new self('not_allowlisted', $netid,
            'Your netID is not authorised for the LUNA OD6 booking system. '
            . 'Please ask the lab administrator to give you access.');
    }

    public static function suspended(string $netid): self
    {
        return new self('suspended', $netid,
            'Your access to the LUNA OD6 booking system has been suspended. '
            . 'Please contact the lab administrator.');
    }
}
