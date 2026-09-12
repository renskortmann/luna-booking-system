<?php

declare(strict_types=1);

namespace Luna;

use DateTimeImmutable;

/**
 * The booking rules, as pure functions: same inputs, same answer, no database
 * and no clock of their own.
 *
 * Times arrive as UTC instants and are compared in the display timezone,
 * because "between 08:00 and 18:00 on a weekday" is a statement about local
 * wall-clock time. Converting a UTC instant to local time always yields
 * exactly one wall time, so the March and October DST transitions cannot
 * produce an ambiguous booking.
 *
 * The administrator bypasses all of this; see BookingService.
 */
final class BookingRules
{
    /**
     * Everything wrong with a proposed booking, as sentences fit to show a user.
     * An empty list means it is acceptable.
     *
     * @return list<string>
     */
    public static function validate(
        RuleSet $rules,
        DateTimeImmutable $startUtc,
        DateTimeImmutable $endUtc,
        DateTimeImmutable $now,
        int $activeBookingCount = 0,
        bool $countsAgainstQuota = true,
    ): array {
        $errors = [];

        if ($endUtc <= $startUtc) {
            return ['A booking must end after it starts.'];
        }

        $zone = $rules->zone();
        $localStart = $startUtc->setTimezone($zone);
        $localEnd = $endUtc->setTimezone($zone);

        $durationMinutes = (int) round(($endUtc->getTimestamp() - $startUtc->getTimestamp()) / 60);

        // --- duration -------------------------------------------------------
        if ($durationMinutes < $rules->minMinutes) {
            $errors[] = 'The shortest booking is ' . self::humanDuration($rules->minMinutes) . '.';
        }

        if ($durationMinutes > $rules->maxMinutes) {
            $errors[] = 'The longest booking is ' . self::humanDuration($rules->maxMinutes) . '.';
        }

        if ($durationMinutes % $rules->slotMinutes !== 0) {
            $errors[] = 'Bookings are made in blocks of ' . $rules->slotMinutes . ' minutes.';
        }

        // --- alignment to the slot grid -------------------------------------
        $startMinutes = (int) $localStart->format('H') * 60 + (int) $localStart->format('i');

        if ((int) $localStart->format('s') !== 0 || $startMinutes % $rules->slotMinutes !== 0) {
            $errors[] = 'Please start on a ' . $rules->slotMinutes . '-minute boundary, such as '
                . self::exampleBoundary($rules) . '.';
        }

        // --- opening hours --------------------------------------------------
        $endMinutes = (int) $localEnd->format('H') * 60 + (int) $localEnd->format('i');
        // A booking that ends exactly at midnight ends on the next calendar day.
        $endsAtMidnight = $endMinutes === 0;
        $sameDay = $localStart->format('Y-m-d') === $localEnd->format('Y-m-d')
            || ($endsAtMidnight && $localStart->modify('+1 day')->format('Y-m-d') === $localEnd->format('Y-m-d'));

        if (!$sameDay) {
            $errors[] = 'A booking must start and finish on the same day.';
        }

        if (!in_array((int) $localStart->format('N'), $rules->openDays, true)) {
            $errors[] = 'The machine can be booked on ' . self::humanDays($rules->openDays) . '.';
        }

        $open = $rules->openMinutes();
        $close = $rules->closeMinutes();
        $effectiveEnd = $endsAtMidnight ? 24 * 60 : $endMinutes;

        if ($sameDay && ($startMinutes < $open || $effectiveEnd > $close)) {
            $errors[] = 'Bookings must fall between ' . $rules->openTime . ' and ' . $rules->closeTime . '.';
        }

        // --- when, relative to now ------------------------------------------
        if (!$rules->allowPast && $startUtc < $now) {
            $errors[] = 'That start time is in the past.';
        }

        $horizon = $now->modify('+' . $rules->maxAdvanceDays . ' days');

        if ($startUtc > $horizon) {
            $errors[] = 'Bookings can be made up to ' . $rules->maxAdvanceDays . ' days ahead.';
        }

        // --- how many the user already holds --------------------------------
        if ($countsAgainstQuota
            && $rules->maxActivePerUser > 0
            && $activeBookingCount >= $rules->maxActivePerUser) {
            $errors[] = 'You already have ' . $rules->maxActivePerUser
                . ' upcoming booking(s). Please cancel one before making another.';
        }

        return $errors;
    }

    /**
     * Whether a user may still change or cancel a booking of their own.
     * Bookings that have started, or are about to, are frozen - the admin can
     * still alter them.
     */
    public static function changeNoticeError(
        RuleSet $rules,
        DateTimeImmutable $startUtc,
        DateTimeImmutable $now,
    ): ?string {
        if ($startUtc <= $now) {
            return 'That booking has already started, so it can no longer be changed. '
                . 'Please ask the lab administrator.';
        }

        $deadline = $startUtc->modify('-' . $rules->minChangeNoticeMinutes . ' minutes');

        if ($rules->minChangeNoticeMinutes > 0 && $now > $deadline) {
            return 'Bookings can only be changed up to ' . self::humanDuration($rules->minChangeNoticeMinutes)
                . ' before they start. Please ask the lab administrator.';
        }

        return null;
    }

    public static function humanDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' minutes';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        $text = $hours . ($hours === 1 ? ' hour' : ' hours');

        return $rest === 0 ? $text : $text . ' ' . $rest . ' minutes';
    }

    /**
     * @param list<int> $days
     */
    public static function humanDays(array $days): string
    {
        $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                  5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

        sort($days);
        $labels = array_map(static fn (int $d): string => $names[$d] ?? (string) $d, $days);

        if ($labels === []) {
            return 'no days (ask the administrator)';
        }

        if (count($labels) === 1) {
            return $labels[0];
        }

        $last = array_pop($labels);

        return implode(', ', $labels) . ' and ' . $last;
    }

    private static function exampleBoundary(RuleSet $rules): string
    {
        $open = $rules->openMinutes();
        $second = $open + $rules->slotMinutes;

        return sprintf('%02d:%02d or %02d:%02d',
            intdiv($open, 60), $open % 60,
            intdiv($second, 60) % 24, $second % 60);
    }
}
