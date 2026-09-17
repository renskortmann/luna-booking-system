<?php

declare(strict_types=1);

namespace Macrolab\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Macrolab\Actor;
use Macrolab\Clock;
use Macrolab\Db;
use Macrolab\Http\HttpException;
use Macrolab\Project;
use Macrolab\Projects;
use Macrolab\TimeEntries;
use Macrolab\TimeEntry;
use Macrolab\TimeEntryException;
use Macrolab\TimeEntryService;
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
        // day has to be built up from entries that are each legal on their own.
        $this->log($this->alice, '2026-09-17', 720);
        $this->log($this->alice, '2026-09-17', 180, $this->other);

        // 15h logged, and another 2h would pass 16h.
        $this->expectException(TimeEntryException::class);
        $this->log($this->alice, '2026-09-17', 120);
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

    private function day(string $day): DateTimeImmutable
    {
        return new DateTimeImmutable($day, new DateTimeZone('UTC'));
    }
}
