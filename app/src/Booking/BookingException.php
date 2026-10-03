<?php

declare(strict_types=1);

namespace Macrolab\Booking;

use RuntimeException;

/**
 * A booking was refused for a reason the user can act on: it breaks a rule, or
 * the slot is taken. Carries every reason at once, so the form can show them
 * all rather than one per attempt.
 */
final class BookingException extends RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public readonly array $errors,
        public readonly int $status = 422,
    ) {
        parent::__construct(implode(' ', $errors));
    }

    /**
     * @param list<string> $errors
     */
    public static function invalid(array $errors): self
    {
        return new self($errors, 422);
    }

    public static function slotTaken(): self
    {
        return new self(['That time is already booked. Please pick another slot.'], 409);
    }
}
