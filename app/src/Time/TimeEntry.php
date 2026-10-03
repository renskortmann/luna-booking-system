<?php

declare(strict_types=1);

namespace Macrolab\Time;

use DateTimeImmutable;
use Macrolab\Clock;

/**
 * One person's hours on one project on one calendar day.
 *
 * `workedOn` is a DATE, held as a UTC midnight. It is a calendar day, not an
 * instant, so it MUST be formatted with ->format() directly and never through
 * Clock::local(), which shifts an instant into the display timezone. In
 * Europe/Amsterdam that shift is invisible (00:00 UTC is 02:00 the same day),
 * which is exactly why the rule is written down: it would start losing a day
 * the moment the display timezone moved west of UTC.
 */
final class TimeEntry
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $projectId,
        public readonly DateTimeImmutable $workedOn,
        public readonly int $minutes,
        public readonly ?string $note,
        public readonly ?string $projectName = null,
        public readonly ?string $projectCode = null,
        public readonly ?string $ownerNetid = null,
        public readonly ?string $ownerName = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            projectId: (int) $row['project_id'],
            workedOn: new DateTimeImmutable((string) $row['worked_on'], Clock::utc()),
            minutes: (int) $row['minutes'],
            note: isset($row['note']) && $row['note'] !== null ? (string) $row['note'] : null,
            projectName: isset($row['project_name']) && $row['project_name'] !== null
                ? (string) $row['project_name'] : null,
            projectCode: isset($row['project_code']) && $row['project_code'] !== null && $row['project_code'] !== ''
                ? (string) $row['project_code'] : null,
            ownerNetid: isset($row['owner_netid']) && $row['owner_netid'] !== null
                ? (string) $row['owner_netid'] : null,
            ownerName: isset($row['owner_name']) && $row['owner_name'] !== null
                ? (string) $row['owner_name'] : null,
        );
    }

    /** The stored form, Y-m-d. */
    public function workedOnDate(): string
    {
        return $this->workedOn->format('Y-m-d');
    }

    /** The day as a person reads it. Not Clock::local() - see the class note. */
    public function workedOnLabel(string $format = 'D j M Y'): string
    {
        return $this->workedOn->format($format);
    }

    public function hoursLabel(): string
    {
        return TimeRules::formatHours($this->minutes);
    }

    public function projectLabel(): string
    {
        $name = $this->projectName ?? ('activity #' . $this->projectId);

        return $this->projectCode === null ? $name : $name . ' (' . $this->projectCode . ')';
    }

    public function ownerLabel(): string
    {
        if ($this->ownerName !== null && $this->ownerName !== '') {
            return $this->ownerName;
        }

        return $this->ownerNetid ?? ('user #' . $this->userId);
    }
}
