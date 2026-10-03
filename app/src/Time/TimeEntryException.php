<?php

declare(strict_types=1);

namespace Macrolab\Time;

use RuntimeException;

/**
 * A proposed time entry the rules refuse, carrying every reason at once so the
 * form can show them together rather than one per submission.
 *
 * Shaped like BookingException but separate from it. Merging the two would mean
 * changing what the booking JSON API returns, for no gain on this side.
 */
final class TimeEntryException extends RuntimeException
{
    /**
     * @param list<string> $errors
     */
    private function __construct(
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
        return new self($errors === [] ? ['That time entry cannot be saved.'] : $errors);
    }
}
