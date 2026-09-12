<?php

declare(strict_types=1);

namespace Luna;

/**
 * The bookable machines. There is one - LUNA OD6 - and the UI shows only that
 * one, but bookings reference a resource row so a second instrument can be
 * added later without touching the schema or the queries.
 */
final class Resources
{
    /**
     * @return array<string, mixed>
     */
    public static function primary(): array
    {
        $row = Db::get()->one(
            'SELECT id, name, slug, description, is_active FROM resources
              WHERE is_active = 1 ORDER BY id LIMIT 1'
        );

        if ($row === null) {
            throw new \RuntimeException(
                'No active resource found. Has app/cli/migrate.php been run?'
            );
        }

        return $row;
    }

    public static function primaryId(): int
    {
        return (int) self::primary()['id'];
    }

    /**
     * Take a row lock on the resource. Every booking write does this first, so
     * that writes for one machine are serialised and two requests cannot both
     * find a slot free and then both fill it.
     *
     * MySQL has no exclusion constraint for time ranges, and relying on InnoDB
     * gap locks would be subtle; one explicit row lock is easy to verify.
     */
    public static function lock(int $resourceId): void
    {
        Db::get()->query('SELECT id FROM resources WHERE id = ? FOR UPDATE', [$resourceId]);
    }
}
