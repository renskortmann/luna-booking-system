<?php

declare(strict_types=1);

namespace Macrolab\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Macrolab\Actor;
use Macrolab\Clock;
use Macrolab\Db;
use Macrolab\Http\HttpException;
use Macrolab\Time\Project;
use Macrolab\Time\Projects;
use Macrolab\Time\TimeEntries;
use Macrolab\Time\TimeEntry;
use Macrolab\Time\TimeEntryException;
use Macrolab\Time\TimeEntryService;
use Macrolab\User;
use Macrolab\Users;

/**
 * Logging, changing and removing time, against a real database.
 *
 * The daily cap and the ownership refusal both depend on what is already
 * stored, so neither can be tested as a calculation.
 */
final class TimeEntryServiceTest extends DatabaseTestCase
{
    private User $alice;
    private User $bob;
    private Project $project;
    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = Users::create('alice', 'Alice');
        $this->bob = Users::create('bob', 'Bob');
        $this->project = Projects::create('Beam alignment', 'BA-12');
        $this->other = Projects::create('Sample prep');

        // Thursday 17 September 2026, 06:00 UTC - 08:00 local.
        Clock::freeze('2026-09-17 06:00:00');
    }

    public function testAnEmployeeCanLogTime(): void
    {
        $entry = $this->log($this->alice, '2026-09-17', 210);

        self::assertSame($this->alice->id, $entry->userId);
        self::assertSame(210, $entry->minutes);
        self::assertSame('2026-09-17', $entry->workedOnDate());
        self::assertSame('Beam alignment', $entry->projectName);
    }

    /**
     * The whole point of storing minutes rather than hours: what the person
     * typed has to survive the round trip exactly.
     */
    public function testThreeAndAHalfHoursSurvivesTheRoundTripExactly(): void
    {
        $entry = $this->log($this->alice, '2026-09-17', 210);
        $reloaded = TimeEntries::find($entry->id);

        self::assertNotNull($reloaded);
        self::assertSame(210, $reloaded->minutes);
        self::assertSame('3:30', $reloaded->hoursLabel());
    }

    public function testTheDayIsStoredAsACalendarDayAndNotShifted(): void
    {
        $entry = $this->log($this->alice, '2026-09-17', 60);

        self::assertSame('2026-09-17', $entry->workedOnDate());
        self::assertSame(
            '2026-09-17',
            Db::get()->value('SELECT worked_on FROM time_entries WHERE id = ?', [$entry->id])
        );
    }

    public function testSeveralEntriesOnOneDayAreFine(): void
    {
        $this->log($this->alice, '2026-09-17', 120);
        $this->log($this->alice, '2026-09-17', 180, $this->other);

        self::assertSame(300, TimeEntries::minutesForUserOnDay(
            $this->alice->id,
            new DateTimeImmutable('2026-09-17', new DateTimeZone('UTC'))
        ));
    }

    public function testTheDailyCapBlocksTheEntryThatWouldExceedIt(): void
    {
        // The default caps are 12 hours per entry and 16 across a day, so the
        // day has to be built up from entries that are each legal on their own,
        // and each on its own project, since a project has one entry a day.
        $this->log($this->alice, '2026-09-17', 720);
        $this->log($this->alice, '2026-09-17', 180, $this->other);

        // 15h logged, and another 2h would pass 16h.
        $this->expectException(TimeEntryException::class);
        $this->expectExceptionMessage('the limit is');
        $this->log($this->alice, '2026-09-17', 120, Projects::create('Detector calibration'));
    }

    public function testASecondEntryForTheSameProjectAndDayIsRefused(): void
    {
        $this->log($this->alice, '2026-09-17', 120);

        $this->expectException(TimeEntryException::class);
        $this->expectExceptionMessage('You already have time on Beam alignment');
        $this->log($this->alice, '2026-09-17', 60);
    }

    public function testAnEntryCannotBeMovedOntoAProjectAndDayThatIsTaken(): void
    {
        $this->log($this->alice, '2026-09-17', 120);
        $moving = $this->log($this->alice, '2026-09-17', 60, $this->other);

        $this->expectException(TimeEntryException::class);
        $this->expectExceptionMessage('You already have time on Beam alignment');

        TimeEntryService::update(
            actor: Actor::forUser($this->alice),
            entry: $moving,
            projectId: $this->project->id,
            workedOn: $this->day('2026-09-17'),
            minutes: 60,
            note: null,
        );
    }

    /** The same project on the same day is taken for Alice, not for Bob. */
    public function testTheSameProjectAndDayIsFreeForSomebodyElse(): void
    {
        $this->log($this->alice, '2026-09-17', 120);
        $entry = $this->log($this->bob, '2026-09-17', 120);

        self::assertSame($this->bob->id, $entry->userId);
    }

    public function testTheUniqueKeyHoldsEvenWhenTheFriendlyCheckIsBypassed(): void
    {
        $this->log($this->alice, '2026-09-17', 120);

        $this->expectException(\PDOException::class);
        Db::get()->insert('time_entries', [
            'user_id'    => $this->alice->id,
            'project_id' => $this->project->id,
            'worked_on'  => '2026-09-17',
            'minutes'    => 60,
            'note'       => null,
            'created_at' => Clock::sql(),
            'updated_at' => Clock::sql(),
        ]);
    }

    public function testFillingAnEmptyCellCreatesTheEntry(): void
    {
        $entry = $this->cell($this->alice, 210, 'stage alignment');

        self::assertNotNull($entry);
        self::assertSame(210, $entry->minutes);
        self::assertSame('stage alignment', $entry->note);
        self::assertSame(1, $this->countEntries($this->alice));
    }

    public function testChangingAFilledCellUpdatesTheSameEntry(): void
    {
        $first = $this->cell($this->alice, 120);
        $second = $this->cell($this->alice, 180, 'longer than planned');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->id, $second->id);
        self::assertSame(180, $second->minutes);
        self::assertSame(1, $this->countEntries($this->alice));
    }

    public function testClearingACellRemovesTheEntry(): void
    {
        $this->cell($this->alice, 120, 'a remark');

        self::assertNull($this->cell($this->alice, null));
        self::assertSame(0, $this->countEntries($this->alice));
    }

    public function testZeroHoursInACellRemovesTheEntry(): void
    {
        $this->cell($this->alice, 120);

        self::assertNull($this->cell($this->alice, 0));
        self::assertSame(0, $this->countEntries($this->alice));
    }

    public function testClearingACellThatWasNeverFilledDoesNothing(): void
    {
        self::assertNull($this->cell($this->alice, null));
        self::assertSame(0, (int) Db::get()->value('SELECT COUNT(*) FROM audit_log WHERE action LIKE ?', ['time_entry_%']));
    }

    public function testARemarkWithoutHoursIsRefused(): void
    {
        $this->expectException(TimeEntryException::class);
        $this->expectExceptionMessage('Enter the hours for this remark.');
        $this->cell($this->alice, null, 'forgot the hours');
    }

    /** Leaving a cell without changing it must not claim a change was made. */
    public function testSavingACellUnchangedLeavesNoAuditEntry(): void
    {
        $this->cell($this->alice, 120, 'same');
        $this->cell($this->alice, 120, 'same');

        self::assertSame(0, (int) Db::get()->value(
            'SELECT COUNT(*) FROM audit_log WHERE action = ?',
            ['time_entry_updated']
        ));
    }

    /**
     * Raising one project's hours is measured against the other projects on
     * that day, not against the cell's own previous value as well.
     */
    public function testTheDailyCapCountsOtherCellsButNotTheCellsOwnOldValue(): void
    {
        $this->cell($this->alice, 600, project: $this->other);   // 10h elsewhere
        $this->cell($this->alice, 300);                          // 5h here: 15h

        // 6h here makes 16h exactly, which is allowed; the old 5h is replaced.
        $entry = $this->cell($this->alice, 360);
        self::assertNotNull($entry);
        self::assertSame(360, $entry->minutes);

        // 7h here would make 17h.
        $this->expectException(TimeEntryException::class);
        $this->cell($this->alice, 420);
    }

    public function testACellOnARetiredProjectCannotBeSaved(): void
    {
        $this->cell($this->alice, 120);
        Projects::setActive($this->project->id, false);

        $this->expectException(HttpException::class);
        $this->cell($this->alice, null);
    }

    public function testTheAdministratorHasNoDaySheet(): void
    {
        $this->expectException(HttpException::class);

        TimeEntryService::saveCell(
            actor: Actor::forAdmin(),
            day: $this->day('2026-09-17'),
            projectId: $this->project->id,
            minutes: 60,
        );
    }

    public function testTheDaySheetListsOneEntryPerProject(): void
    {
        $this->cell($this->alice, 120);
        $this->cell($this->alice, 60, project: $this->other);
        $this->log($this->alice, '2026-09-16', 30);
        $this->log($this->bob, '2026-09-17', 45);

        $entries = TimeEntries::forUserOnDay($this->alice->id, $this->day('2026-09-17'));

        // Neither the other day nor Bob's time on this one.
        self::assertCount(2, $entries);
        self::assertSame(120, $entries[$this->project->id]->minutes);
        self::assertSame(60, $entries[$this->other->id]->minutes);
    }

    public function testTheDailyCapIsPerPersonNotPerLab(): void
    {
        $this->log($this->alice, '2026-09-17', 720);
        $this->log($this->alice, '2026-09-17', 180, $this->other);

        // Alice is nearly capped; Bob's own day is untouched by that.
        $entry = $this->log($this->bob, '2026-09-17', 300);
        self::assertSame($this->bob->id, $entry->userId);
    }

    public function testTimeCannotBeLoggedAgainstARetiredProject(): void
    {
        Projects::setActive($this->project->id, false);

        $this->expectException(HttpException::class);
        $this->log($this->alice, '2026-09-17', 60);
    }

    public function testAnEmployeeCanChangeTheirOwnEntry(): void
    {
        $entry = $this->log($this->alice, '2026-09-17', 120);

        $updated = TimeEntryService::update(
            actor: Actor::forUser($this->alice),
            entry: $entry,
            projectId: $this->other->id,
            workedOn: $this->day('2026-09-16'),
            minutes: 90,
            note: 'moved to the right project',
        );

        self::assertSame(90, $updated->minutes);
        self::assertSame('2026-09-16', $updated->workedOnDate());
        self::assertSame('Sample prep', $updated->projectName);
    }

    public function testChangingAnEntryDoesNotCountItselfAgainstTheDailyCap(): void
    {
        // 11h40 logged. Raising it to 12h must be compared against the day
        // without this entry in it, not against 11h40 + 12h, which would trip
        // the 16h cap and refuse a perfectly ordinary correction.
        $entry = $this->log($this->alice, '2026-09-17', 700);

        $updated = TimeEntryService::update(
            actor: Actor::forUser($this->alice),
            entry: $entry,
            projectId: $this->project->id,
            workedOn: $this->day('2026-09-17'),
            minutes: 720,
            note: null,
        );

        self::assertSame(720, $updated->minutes);
    }

    public function testAnEmployeeCannotChangeSomebodyElsesEntry(): void
    {
        $entry = $this->log($this->alice, '2026-09-17', 120);

        $this->expectException(HttpException::class);

        TimeEntryService::update(
            actor: Actor::forUser($this->bob),
            entry: $entry,
            projectId: $this->project->id,
            workedOn: $this->day('2026-09-17'),
            minutes: 60,
            note: null,
        );
    }

    public function testARefusedChangeIsRecordedInTheAuditLog(): void
    {
        $entry = $this->log($this->alice, '2026-09-17', 120);

        try {
            TimeEntryService::delete(Actor::forUser($this->bob), $entry);
            self::fail('Expected the attempt to be refused.');
        } catch (HttpException) {
            // expected
        }

        self::assertSame(1, (int) Db::get()->value(
            'SELECT COUNT(*) FROM audit_log WHERE action = ? AND target_id = ?',
            ['time_entry_change_refused', $entry->id]
        ));
    }

    /** The inverse of the booking rule, and the crux of the read-only overview. */
    public function testTheAdministratorCannotChangeAnEmployeesEntry(): void
    {
        $entry = $this->log($this->alice, '2026-09-17', 120);

        $this->expectException(HttpException::class);
        TimeEntryService::delete(Actor::forAdmin(), $entry);
    }

    public function testTheAdministratorHasNoTimesheetOfTheirOwn(): void
    {
        $this->expectException(HttpException::class);

        TimeEntryService::create(
            actor: Actor::forAdmin(),
            projectId: $this->project->id,
            workedOn: $this->day('2026-09-17'),
            minutes: 60,
            note: null,
        );
    }

    public function testAnEmployeeCanRemoveTheirOwnEntry(): void
    {
        $entry = $this->log($this->alice, '2026-09-17', 120);

        TimeEntryService::delete(Actor::forUser($this->alice), $entry);

        self::assertNull(TimeEntries::find($entry->id));
    }

    public function testEveryWriteIsRecordedInTheAuditLog(): void
    {
        $entry = $this->log($this->alice, '2026-09-17', 120);

        TimeEntryService::update(
            actor: Actor::forUser($this->alice),
            entry: $entry,
            projectId: $this->project->id,
            workedOn: $this->day('2026-09-17'),
            minutes: 150,
            note: null,
        );

        $reloaded = TimeEntries::find($entry->id);
        self::assertNotNull($reloaded);
        TimeEntryService::delete(Actor::forUser($this->alice), $reloaded);

        foreach (['time_entry_created', 'time_entry_updated', 'time_entry_deleted'] as $action) {
            self::assertSame(1, (int) Db::get()->value(
                'SELECT COUNT(*) FROM audit_log WHERE action = ?',
                [$action]
            ), 'expected one ' . $action . ' entry');
        }
    }

    /**
     * The deletion audit entry is what remains of the entry, so it has to carry
     * enough to reconstruct what was removed.
     */
    public function testTheDeletionAuditEntryCarriesTheWholeRecord(): void
    {
        $entry = $this->log($this->alice, '2026-09-17', 210, note: 'recalibrated the stage');
        TimeEntryService::delete(Actor::forUser($this->alice), $entry);

        $details = (string) Db::get()->value(
            'SELECT details FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1',
            ['time_entry_deleted']
        );

        self::assertStringContainsString('Beam alignment', $details);
        self::assertStringContainsString('2026-09-17', $details);
        self::assertStringContainsString('recalibrated the stage', $details);
    }

    public function testAProjectWithTimeOnItCannotBeDeleted(): void
    {
        $this->log($this->alice, '2026-09-17', 60);

        self::assertSame(1, Projects::countEntries($this->project->id));
    }

    public function testAUserWithTimeOnRecordIsCountedSoTheyAreNotDeleted(): void
    {
        $this->log($this->alice, '2026-09-17', 60);

        self::assertSame(1, Users::countTimeEntries($this->alice->id));
        self::assertSame(0, Users::countTimeEntries($this->bob->id));
    }

    private function log(
        User $user,
        string $day,
        int $minutes,
        ?Project $project = null,
        ?string $note = null,
    ): TimeEntry {
        return TimeEntryService::create(
            actor: Actor::forUser($user),
            projectId: ($project ?? $this->project)->id,
            workedOn: $this->day($day),
            minutes: $minutes,
            note: $note,
        );
    }

    /** Save one day-sheet cell on 17 September, as the grid does. */
    private function cell(
        User $user,
        ?int $minutes,
        ?string $note = null,
        ?Project $project = null,
    ): ?TimeEntry {
        return TimeEntryService::saveCell(
            actor: Actor::forUser($user),
            day: $this->day('2026-09-17'),
            projectId: ($project ?? $this->project)->id,
            minutes: $minutes,
            note: $note,
        );
    }

    private function countEntries(User $user): int
    {
        return (int) Db::get()->value('SELECT COUNT(*) FROM time_entries WHERE user_id = ?', [$user->id]);
    }

    private function day(string $day): DateTimeImmutable
    {
        return new DateTimeImmutable($day, new DateTimeZone('UTC'));
    }
}
