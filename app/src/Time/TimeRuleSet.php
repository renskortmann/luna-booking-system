<?php

declare(strict_types=1);

namespace Macrolab\Time;

use Macrolab\Settings;

/**
 * The time registration rules, resolved once and passed around as a value.
 *
 * The same shape as RuleSet, and for the same reason: TimeRules is pure, so the
 * settings lookup has to happen somewhere else.
 */
final class TimeRuleSet
{
    public function __construct(
        public readonly int $minMinutes = 5,
        public readonly int $maxMinutesPerEntry = 720,
        public readonly int $maxMinutesPerDay = 960,
        public readonly int $maxFutureDays = 7,
        public readonly int $maxBackdateDays = 90,
    ) {
    }

    public static function fromSettings(): self
    {
        return new self(
            minMinutes: max(1, Settings::int('time_min_entry_minutes')),
            maxMinutesPerEntry: max(1, Settings::int('time_max_entry_minutes')),
            maxMinutesPerDay: max(1, Settings::int('time_max_day_minutes')),
            maxFutureDays: max(0, Settings::int('time_max_future_days')),
            maxBackdateDays: max(0, Settings::int('time_max_backdate_days')),
        );
    }

    /**
     * A rule set with a few values replaced. Tests use this so that each one
     * states only the rule it is about.
     */
    public function with(
        ?int $minMinutes = null,
        ?int $maxMinutesPerEntry = null,
        ?int $maxMinutesPerDay = null,
        ?int $maxFutureDays = null,
        ?int $maxBackdateDays = null,
    ): self {
        return new self(
            minMinutes: $minMinutes ?? $this->minMinutes,
            maxMinutesPerEntry: $maxMinutesPerEntry ?? $this->maxMinutesPerEntry,
            maxMinutesPerDay: $maxMinutesPerDay ?? $this->maxMinutesPerDay,
            maxFutureDays: $maxFutureDays ?? $this->maxFutureDays,
            maxBackdateDays: $maxBackdateDays ?? $this->maxBackdateDays,
        );
    }
}
