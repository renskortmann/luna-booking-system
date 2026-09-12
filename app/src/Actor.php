<?php

declare(strict_types=1);

namespace Luna;

/**
 * Whoever is making the current request: a lab member, or the administrator.
 * Passed to BookingPolicy, which is the only thing that decides what they may
 * change.
 */
final class Actor
{
    private function __construct(
        public readonly ?User $user,
        public readonly bool $isAdmin,
    ) {
    }

    public static function forUser(User $user): self
    {
        return new self($user, false);
    }

    public static function forAdmin(): self
    {
        return new self(null, true);
    }

    public function userId(): ?int
    {
        return $this->user?->id;
    }

    public function label(): string
    {
        return $this->isAdmin ? 'Administrator' : ($this->user?->label() ?? 'unknown');
    }
}
