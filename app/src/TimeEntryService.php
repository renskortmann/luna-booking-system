<?php

declare(strict_types=1);

namespace Macrolab;

use DateTimeImmutable;
use Macrolab\Http\HttpException;
use RuntimeException;

/**
 * Creating, changing and deleting time entries: the ownership check, the rule
 * checks and the audit entry, in one transaction.
 *
 * Note what is absent. There is no row lock, because unlike a booking there is
 * nothing here two requests can both claim. The per-day cap is advisory, and a
 * race that lets somebody log sixteen hours and five minutes is harmless.
 * Copying Resources::lock() would be borrowing a mechanism whose reason does
 * not apply.
 */
final class TimeEntryService
{
    public static function create(
        Actor $actor,
        int $projectId,
        DateTimeImmutable $workedOn,
        int $minutes,
        ?string $note = null,
    ): TimeEntry {
        $userId = $actor->userId();

        if ($userId === null) {
            throw HttpException::forbidden(
                'The administrator does not keep a timesheet. Time is logged by lab members.'
            );
        }

        $project = Projects::requireActive($projectId);
        $note = self::normaliseNote($note);

        self::assertRules($workedOn, $minutes, $note, $userId, null);

        return Db::get()->transaction(static function () use (
            $userId, $project, $workedOn, $minutes, $note
        ): TimeEntry {
            $now = Clock::sql();

            $id = Db::get()->insert('time_entries', [
                'user_id'    => $userId,
                'project_id' => $project->id,
                'worked_on'  => $workedOn->format('Y-m-d'),
                'minutes'    => $minutes,
                'note'       => $note,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $entry = TimeEntries::find($id);

            if ($entry === null) {
                throw new RuntimeException('Time entry row disappeared immediately after insert.');
            }

            Audit::log('time_entry_created', 'time_entry', $id, [
                'owner_id'  => $userId,
                'project'   => $project->name,
                'worked_on' => $entry->workedOnDate(),
                'minutes'   => $minutes,
            ]);

            return $entry;
        });
    }

    public static function update(
        Actor $actor,
        TimeEntry $entry,
        int $projectId,
        DateTimeImmutable $workedOn,
        int $minutes,
        ?string $note = null,
    ): TimeEntry {
        TimeEntryPolicy::assertCanModify($entry, $actor);

        $project = Projects::requireActive($projectId);
        $note = self::normaliseNote($note);

        self::assertRules($workedOn, $minutes, $note, $entry->userId, $entry->id);

        return Db::get()->transaction(static function () use (
            $entry, $project, $workedOn, $minutes, $note
        ): TimeEntry {
            Db::get()->update('time_entries', [
                'project_id' => $project->id,
                'worked_on'  => $workedOn->format('Y-m-d'),
                'minutes'    => $minutes,
                'note'       => $note,
                'updated_at' => Clock::sql(),
            ], 'id = ?', [$entry->id]);

            $updated = TimeEntries::find($entry->id);

            if ($updated === null) {
                throw new RuntimeException('Time entry disappeared while being updated.');
            }

            Audit::log('time_entry_updated', 'time_entry', $entry->id, [
                'owner_id' => $entry->userId,
                'from' => [
                    'project'   => $entry->projectName,
                    'worked_on' => $entry->workedOnDate(),
                    'minutes'   => $entry->minutes,
                ],
                'to' => [
                    'project'   => $project->name,
                    'worked_on' => $updated->workedOnDate(),
                    'minutes'   => $minutes,
                ],
            ]);

            return $updated;
        });
    }

    /**
     * Remove an entry outright.
     *
     * Time entries are deleted rather than kept with a cancelled status: unlike
     * a booking, a withdrawn entry says nothing useful about who had what. The
     * audit record carries the whole of what was removed, and is what remains
     * of it.
     */
    public static function delete(Actor $actor, TimeEntry $entry): void
    {
        TimeEntryPolicy::assertCanModify($entry, $actor);

        Db::get()->transaction(static function () use ($entry): void {
            Audit::log('time_entry_deleted', 'time_entry', $entry->id, [
                'owner_id'  => $entry->userId,
                'owner'     => $entry->ownerNetid,
                'project'   => $entry->projectName,
                'worked_on' => $entry->workedOnDate(),
                'minutes'   => $entry->minutes,
                'note'      => $entry->note,
            ]);

            Db::get()->query('DELETE FROM time_entries WHERE id = ?', [$entry->id]);
        });
    }

    /**
     * @throws TimeEntryException
     */
    private static function assertRules(
        DateTimeImmutable $workedOn,
        int $minutes,
        ?string $note,
        int $ownerUserId,
        ?int $excludeEntryId,
    ): void {
        $errors = TimeRules::validate(
            rules: TimeRuleSet::fromSettings(),
            workedOn: $workedOn,
            minutes: $minutes,
            // Read here, not inside TimeRules, which stays pure.
            today: TimeRules::today(),
            minutesAlreadyOnDay: TimeEntries::minutesForUserOnDay($ownerUserId, $workedOn, $excludeEntryId),
            note: $note,
        );

        if ($errors !== []) {
            throw TimeEntryException::invalid($errors);
        }
    }

    /** Trim to null, but never truncate: TimeRules rejects an over-long note. */
    private static function normaliseNote(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : $note;
    }
}
