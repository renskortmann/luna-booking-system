<?php

declare(strict_types=1);

namespace Luna;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The only source of "now" in the application. Everything reads the clock from
 * here so that tests can freeze it - which is what makes the booking rules,
 * the invite expiry and the DST boundaries testable at all.
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    /** Current instant, always in UTC. */
    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** Freeze the clock. Tests only. */
    public static function freeze(DateTimeImmutable|string $when): DateTimeImmutable
    {
        return self::$frozen = $when instanceof DateTimeImmutable
            ? $when->setTimezone(new DateTimeZone('UTC'))
            : new DateTimeImmutable($when, new DateTimeZone('UTC'));
    }

    public static function unfreeze(): void
    {
        self::$frozen = null;
    }

    /** UTC string in the format every DATETIME column uses. */
    public static function sql(?DateTimeImmutable $moment = null): string
    {
        return ($moment ?? self::now())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    /** The timezone users see, from configuration. */
    public static function displayZone(): DateTimeZone
    {
        return new DateTimeZone(Config::string('app.display_timezone', 'Europe/Amsterdam'));
    }

    /** Parse a UTC DATETIME string from the database. */
    public static function fromSql(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, self::utc());
    }

    /** Render a UTC instant in the display timezone. */
    public static function local(DateTimeImmutable $moment, string $format = 'Y-m-d H:i'): string
    {
        return $moment->setTimezone(self::displayZone())->format($format);
    }
    /**
     * Parse a time submitted by a browser into a UTC instant.
     *
     * A value carrying an offset ("2026-09-14T09:00:00+02:00" or "...Z") is
     * taken at face value. A bare local time ("2026-09-14 09:00") is read in
     * the display timezone, because that is what the person typing it meant.
     */
    public static function parseInstant(string $value): ?DateTimeImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $hasOffset = preg_match('/(Z|[+-]\\d{2}:?\\d{2})$/', $value) === 1;

        try {
            $parsed = new DateTimeImmutable($value, $hasOffset ? self::utc() : self::displayZone());
        } catch (\Exception) {
            return null;
        }

        return $parsed->setTimezone(self::utc());
    }
}
