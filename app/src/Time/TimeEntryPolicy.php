<?php

declare(strict_types=1);

namespace Macrolab\Time;

use Macrolab\Actor;
use Macrolab\Audit;
use Macrolab\Http\HttpException;

/**
 * Who may change a time entry. Every write path goes through
 * assertCanModify(), never through a check in a template or a hidden form
 * field.
 */
final class TimeEntryPolicy
{
    public static function canModify(?TimeEntry $entry, ?Actor $actor): bool
    {
        if ($entry === null || $actor === null) {
            return false;
        }

        /*
         * Read this carefully, because it is the opposite of BookingPolicy and
         * that is deliberate.
         *
         * The administrator may change any booking, because managing the shared
         * calendar is their job. They may NOT change a time entry. The
         * requirement is a read-only overview: there is no approval step for
         * them to perform, and nobody edits somebody else's timesheet.
         *
         * TimeEntryPolicyTest pins this, so that it is not "fixed" later by
         * analogy with the booking policy.
         */
        if ($actor->isAdmin) {
            return false;
        }

        return $actor->userId() !== null && $actor->userId() === $entry->userId;
    }

    /**
     * @throws HttpException 403 when the actor does not own the entry
     */
    public static function assertCanModify(?TimeEntry $entry, ?Actor $actor): void
    {
        if (self::canModify($entry, $actor)) {
            return;
        }

        if ($entry !== null && $actor !== null) {
            Audit::log('time_entry_change_refused', 'time_entry', $entry->id, [
                'reason'       => $actor->isAdmin ? 'admin_is_read_only' : 'not_owner',
                'owner_id'     => $entry->userId,
                'attempted_by' => $actor->userId(),
            ]);
        }

        throw HttpException::forbidden('That time entry belongs to someone else.');
    }

    /** The owner sees their own entries; the administrator sees everyone's. */
    public static function canSee(?TimeEntry $entry, ?Actor $actor): bool
    {
        if ($entry === null || $actor === null) {
            return false;
        }

        return $actor->isAdmin || $actor->userId() === $entry->userId;
    }
}
