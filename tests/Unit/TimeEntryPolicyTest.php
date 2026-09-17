<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Macrolab\Actor;
use Macrolab\TimeEntry;
use Macrolab\TimeEntryPolicy;
use Macrolab\User;
use PHPUnit\Framework\TestCase;

/**
 * "Employees own their entries; the administrator's view is read-only."
 *
 * The second half of that is the opposite of BookingPolicy, so it is pinned
 * here by name: an administrator may change any booking, and no time entry.
 */
final class TimeEntryPolicyTest extends TestCase
{
    public function testOwnerMayChangeTheirOwnEntry(): void
    {
        self::assertTrue(TimeEntryPolicy::canModify(
            $this->entry(ownerId: 1),
            Actor::forUser($this->user(1, 'alice'))
        ));
    }

    public function testAnotherEmployeeMayNotChangeSomebodyElsesEntry(): void
    {
        self::assertFalse(TimeEntryPolicy::canModify(
            $this->entry(ownerId: 1),
            Actor::forUser($this->user(2, 'bob'))
        ));
    }

    /**
     * Deliberately the inverse of BookingPolicy::canModify(). Time registration
     * has no approval step, so the administrator only ever reads. If this test
     * is failing because somebody "fixed" the policy to match the booking one,
     * the policy is what is wrong.
     */
    public function testTheAdministratorMayNotChangeAnybodysTimeEntry(): void
    {
        self::assertFalse(TimeEntryPolicy::canModify(
            $this->entry(ownerId: 1),
            Actor::forAdmin()
        ));
    }

    public function testNobodySignedInMayChangeAnything(): void
    {
        self::assertFalse(TimeEntryPolicy::canModify($this->entry(ownerId: 1), null));
    }

    public function testAMissingEntryIsNotModifiable(): void
    {
        self::assertFalse(TimeEntryPolicy::canModify(null, Actor::forUser($this->user(1, 'alice'))));
    }

    public function testTheOwnerAndTheAdministratorBothSeeAnEntry(): void
    {
        $entry = $this->entry(ownerId: 1);

        self::assertTrue(TimeEntryPolicy::canSee($entry, Actor::forUser($this->user(1, 'alice'))));
        self::assertTrue(TimeEntryPolicy::canSee($entry, Actor::forAdmin()));
        self::assertFalse(TimeEntryPolicy::canSee($entry, Actor::forUser($this->user(2, 'bob'))));
        self::assertFalse(TimeEntryPolicy::canSee($entry, null));
    }

    private function user(int $id, string $netid): User
    {
        return new User(
            id: $id,
            netid: $netid,
            displayName: ucfirst($netid),
            email: $netid . '@tudelft.nl',
            status: 'approved',
        );
    }

    private function entry(int $ownerId): TimeEntry
    {
        return new TimeEntry(
            id: 42,
            userId: $ownerId,
            projectId: 3,
            workedOn: new DateTimeImmutable('2026-09-14', new DateTimeZone('UTC')),
            minutes: 210,
            note: 'recalibrated the stage',
        );
    }
}
