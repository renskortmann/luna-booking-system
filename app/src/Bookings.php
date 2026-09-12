<?php

declare(strict_types=1);

namespace Luna;

use DateTimeImmutable;

/**
 * Every query against the `bookings` table.
 */
final class Bookings
{
    private const SELECT = 'SELECT b.id, b.resource_id, b.user_id, b.starts_at, b.ends_at,
                                   b.purpose, b.status, b.created_by_admin,
                                   u.netid AS owner_netid, u.display_name AS owner_name
                              FROM bookings b
                              JOIN users u ON u.id = b.user_id';

    public static function find(int $id): ?Booking
    {
        $row = Db::get()->one(self::SELECT . ' WHERE b.id = ?', [$id]);

        return $row === null ? null : Booking::fromRow($row);
    }

    /**
     * Confirmed bookings overlapping a window, for the calendar feed.
     *
     * @return list<Booking>
     */
    public static function inWindow(int $resourceId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = Db::get()->all(
            self::SELECT . ' WHERE b.resource_id = ?
                               AND b.status = "confirmed"
                               AND b.starts_at < ?
                               AND b.ends_at > ?
                          ORDER BY b.starts_at',
            [$resourceId, Clock::sql($to), Clock::sql($from)]
        );

        return array_map([Booking::class, 'fromRow'], $rows);
    }

    /**
     * @return list<Booking>
     */
    public static function forUser(int $userId, bool $upcomingOnly = true): array
    {
        $sql = self::SELECT . ' WHERE b.user_id = ? AND b.status = "confirmed"';
        $params = [$userId];

        if ($upcomingOnly) {
            $sql .= ' AND b.ends_at > ?';
            $params[] = Clock::sql();
        }

        return array_map([Booking::class, 'fromRow'], Db::get()->all($sql . ' ORDER BY b.starts_at', $params));
    }

    /**
     * Bookings for the admin management screen, newest first.
     *
     * @return list<Booking>
     */
    public static function recent(int $resourceId, int $limit = 200, bool $includeCancelled = false): array
    {
        $sql = self::SELECT . ' WHERE b.resource_id = ?';

        if (!$includeCancelled) {
            $sql .= ' AND b.status = "confirmed"';
        }

        $sql .= ' ORDER BY b.starts_at DESC LIMIT ' . max(1, min(1000, $limit));

        return array_map([Booking::class, 'fromRow'], Db::get()->all($sql, [$resourceId]));
    }

    /** How many upcoming confirmed bookings a user holds, for the quota check. */
    public static function countUpcomingForUser(int $userId, ?int $excludeBookingId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM bookings
                 WHERE user_id = ? AND status = "confirmed" AND ends_at > ?';
        $params = [$userId, Clock::sql()];

        if ($excludeBookingId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeBookingId;
        }

        return (int) Db::get()->value($sql, $params);
    }

    /**
     * A confirmed booking overlapping the given interval, if there is one.
     *
     * Must be called inside the transaction that holds the resource lock; on
     * its own it is only a hint, because another request could insert a
     * conflicting row a moment later.
     */
    public static function findOverlap(
        int $resourceId,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?int $excludeBookingId = null,
    ): ?Booking {
        $sql = self::SELECT . ' WHERE b.resource_id = ?
                                  AND b.status = "confirmed"
                                  AND b.starts_at < ?
                                  AND b.ends_at > ?';
        $params = [$resourceId, Clock::sql($end), Clock::sql($start)];

        if ($excludeBookingId !== null) {
            $sql .= ' AND b.id <> ?';
            $params[] = $excludeBookingId;
        }

        $row = Db::get()->one($sql . ' LIMIT 1', $params);

        return $row === null ? null : Booking::fromRow($row);
    }
}
