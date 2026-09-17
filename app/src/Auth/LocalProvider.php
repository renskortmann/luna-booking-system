<?php

declare(strict_types=1);

namespace Macrolab\Auth;

use Macrolab\Audit;
use Macrolab\Csrf;
use Macrolab\Http\Request;
use Macrolab\Http\Response;
use Macrolab\Password;
use Macrolab\RateLimit;
use Macrolab\Session;
use Macrolab\Users;
use Macrolab\View;

/**
 * Stage 1: netID plus a password chosen by the user through an invite link.
 *
 * A bridge until TU Delft ICT registers the service provider, not a permanent
 * second way in - see the cutover runbook in README.md.
 */
final class LocalProvider implements ProviderInterface
{
    public function label(): string
    {
        return 'netID and password';
    }

    public function startLogin(Request $request): Response
    {
        return View::page('login', [
            'title'      => 'Sign in',
            'netid'      => $request->post('netid', '') ?? '',
            'error'      => null,
            'csrf'       => Csrf::field(),
        ]);
    }

    /**
     * Verify netID and password.
     *
     * Every failure - unknown netID, no password set yet, wrong password -
     * returns null and produces the same message, so the response cannot be
     * used to find out who has an account. The distinctions are recorded in
     * the audit log, where only the admin can see them.
     */
    public function completeLogin(Request $request): ?Identity
    {
        $netid = Users::normaliseNetid($request->post('netid', '') ?? '');
        $password = (string) ($request->post['password'] ?? '');

        if ($netid === '' || $password === '') {
            return null;
        }

        RateLimit::assertAllowed($netid);

        $user = Users::findByNetid($netid);

        if ($user === null) {
            // Equalise the timing against the case where the account exists.
            Password::dummyVerify($password);
            RateLimit::record($netid, false);
            Audit::log('login_denied_not_allowlisted', 'user', null,
                ['netid' => $netid, 'method' => 'local'],
                actorType: 'anonymous', actorLabel: $netid);

            return null;
        }

        if (!$user->hasPassword()) {
            Password::dummyVerify($password);
            RateLimit::record($netid, false);
            Audit::log('login_failed_no_password', 'user', $user->id, ['netid' => $netid],
                actorType: 'anonymous', actorLabel: $netid);

            return null;
        }

        if (!Password::verify($password, (string) $user->passwordHash)) {
            RateLimit::record($netid, false);
            Audit::log('login_failed', 'user', $user->id, ['netid' => $netid, 'method' => 'local'],
                actorType: 'anonymous', actorLabel: $netid);

            return null;
        }

        // Upgrade the stored hash if the parameters have since been raised.
        if (Password::needsRehash((string) $user->passwordHash)) {
            Users::setPasswordHash($user->id, Password::hash($password));
        }

        RateLimit::record($netid, true);
        RateLimit::clear($netid);

        return new Identity(
            netid: $user->netid,
            email: $user->email,
            displayName: $user->displayName,
            method: 'local',
        );
    }
}
