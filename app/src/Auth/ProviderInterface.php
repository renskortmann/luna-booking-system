<?php

declare(strict_types=1);

namespace Luna\Auth;

use Luna\Http\Request;
use Luna\Http\Response;

/**
 * The seam between "how someone proved who they are" and everything else.
 *
 * LocalProvider (stage 1) renders a password form; SamlProvider (stage 2)
 * redirects to login.tudelft.nl. Neither decides whether the person is allowed
 * in - that check lives in Auth::signIn() and is written exactly once, which is
 * what makes the SSO cutover a configuration change.
 */
interface ProviderInterface
{
    /** Short name for the sign-in button or heading. */
    public function label(): string;

    /** Begin authentication: render a form, or hand off to the identity provider. */
    public function startLogin(Request $request): Response;

    /**
     * Finish authentication.
     *
     * @return Identity|null the verified identity, or null when the attempt
     *                       failed and the caller should render the form again
     */
    public function completeLogin(Request $request): ?Identity;
}
