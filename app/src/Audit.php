<?php

declare(strict_types=1);

namespace Luna;

/**
 * Append-only record of who did what. Written for every booking change, every
 * allowlist change, every settings change and every login outcome - including
 * the refusals, which are the entries that matter when something looks wrong.
 */
final class Audit
{
    /**
     * @param array<string, mixed> $details
     */
    public static function log(
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $details = [],
        ?string $actorType = null,
        ?int $actorId = null,
        ?string $actorLabel = null,
    ): void {
        $request = Context::request();

        if ($actorType === null) {
            [$actorType, $actorId, $actorLabel] = self::currentActor();
        }

        try {
            Db::get()->insert('audit_log', [
                'actor_type'  => $actorType,
                'actor_id'    => $actorId,
                'actor_label' => substr($actorLabel ?? $actorType, 0, 128),
                'action'      => substr($action, 0, 64),
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'details'     => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES),
                'ip'          => $request?->ipBinary(),
                'user_agent'  => $request?->userAgent(),
                'created_at'  => Clock::sql(),
            ]);
        } catch (\Throwable $e) {
            // An audit write must never take down the action it is recording;
            // losing the entry is bad, losing the booking is worse.
            error_log('[luna] audit write failed for "' . $action . '": ' . $e->getMessage());
        }
    }

    /**
     * @return array{0: string, 1: int|null, 2: string}
     */
    private static function currentActor(): array
    {
        if (Auth::isAdmin()) {
            return ['admin', null, 'admin:' . (Auth::adminUsername() ?? 'unknown')];
        }

        $user = Auth::user();
        if ($user !== null) {
            return ['user', $user->id, $user->netid];
        }

        return ['anonymous', null, 'anonymous'];
    }

    /** Delete entries older than the retention window. Used by the prune command. */
    public static function prune(int $days): int
    {
        $cutoff = Clock::sql(Clock::now()->modify('-' . max(1, $days) . ' days'));

        return Db::get()->query('DELETE FROM audit_log WHERE created_at < ?', [$cutoff])->rowCount();
    }
}
