<?php

declare(strict_types=1);

namespace Macrolab\Auth;

/**
 * A verified identity, as an authentication provider reports it. Carries no
 * authorisation: whether this person may actually use the system is decided
 * afterwards, by Auth against the allowlist.
 */
final class Identity
{
    public function __construct(
        public readonly string $netid,
        public readonly ?string $email = null,
        public readonly ?string $displayName = null,
        public readonly ?string $samlNameId = null,
        public readonly string $method = 'local',
    ) {
    }
}
