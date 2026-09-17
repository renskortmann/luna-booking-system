<?php

declare(strict_types=1);

namespace Macrolab;

use DateTimeZone;

/**
 * A snapshot of the booking rules. Passed explicitly into BookingRules so that
 * the rules can be exercised in tests without a database or a settings table.
 */
final class RuleSet
{
    public function __construct(
        public readonly int $slotMinutes = 30,
        /** @var list<int> ISO-8601 weekday numbers, Monday = 1 */
        public readonly array $openDays = [1, 2, 3, 4, 5],
        public readonly string $openTime = '08:00',
        public readonly string $closeTime = '18:00',
        public readonly int $minMinutes = 30,
        public readonly int $maxMinutes = 240,
        public readonly int $maxAdvanceDays = 60,
        public readonly int $maxActivePerUser = 3,
        public readonly int $minChangeNoticeMinutes = 60,
        public readonly bool $allowPast = false,
        public readonly string $timezone = 'Europe/Amsterdam',
    ) {
    }

    public static function fromSettings(): self
    {
        return new self(
            slotMinutes: max(5, Settings::int('slot_minutes')),
            openDays: Settings::openDays(),
            openTime: Settings::get('open_time'),
            closeTime: Settings::get('close_time'),
            minMinutes: max(5, Settings::int('min_booking_minutes')),
            // Set in days by the admin; minutes are the unit the rules use.
            maxMinutes: max(5, Settings::int('max_booking_days') * 24 * 60),
            maxAdvanceDays: max(1, Settings::int('max_advance_days')),
            maxActivePerUser: max(0, Settings::int('max_active_bookings_per_user')),
            minChangeNoticeMinutes: max(0, Settings::int('min_change_notice_minutes')),
            allowPast: Settings::bool('allow_booking_in_past'),
            timezone: Config::string('app.display_timezone', 'Europe/Amsterdam'),
        );
    }

    /** Build a rule set for a test, overriding only what matters to it. */
    public static function with(mixed ...$overrides): self
    {
        return new self(...$overrides);
    }

    public function zone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }

    /** Minutes from local midnight, for comparing against a booking's start. */
    public function openMinutes(): int
    {
        return self::toMinutes($this->openTime, 0);
    }

    public function closeMinutes(): int
    {
        return self::toMinutes($this->closeTime, 24 * 60);
    }

    private static function toMinutes(string $time, int $fallback): int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m) !== 1) {
            return $fallback;
        }

        return min(24 * 60, (int) $m[1] * 60 + (int) $m[2]);
    }
}
