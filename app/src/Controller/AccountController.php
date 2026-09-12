<?php

declare(strict_types=1);

namespace Luna\Controller;

use Luna\Audit;
use Luna\Auth;
use Luna\Bookings;
use Luna\Config;
use Luna\Csrf;
use Luna\Http\Request;
use Luna\Http\Response;
use Luna\Password;
use Luna\Session;
use Luna\Settings;
use Luna\Users;
use Luna\View;

/**
 * The signed-in user's own account: their upcoming bookings and, while local
 * accounts are in use, their password.
 */
final class AccountController
{
    public function show(Request $request): Response
    {
        $user = Auth::requireUser();
        $error = null;
        $status = 200;

        if ($request->isPost()) {
            Csrf::verify($request);

            if (!Settings::localLoginEnabled() || !$user->hasPassword()) {
                $error = 'Your account signs in with TU Delft SSO, so there is no password to change.';
            } else {
                $current = (string) ($request->post['current_password'] ?? '');
                $new = (string) ($request->post['new_password'] ?? '');
                $confirm = (string) ($request->post['new_password_confirm'] ?? '');

                if (!Password::verify($current, (string) $user->passwordHash)) {
                    $error = 'Your current password is not correct.';
                } elseif ($new !== $confirm) {
                    $error = 'The two new passwords do not match.';
                } else {
                    $error = Password::policyError($new, $user->netid);
                }

                if ($error === null) {
                    Users::setPasswordHash($user->id, Password::hash($new));
                    Audit::log('password_changed', 'user', $user->id);
                    Session::flash('success', 'Your password has been changed.');

                    return Response::redirect('/account');
                }
            }

            $status = 400;
        }

        return View::page('account', [
            'title'     => 'My account',
            'user'      => $user,
            'bookings'  => Bookings::forUser($user->id),
            'canChange' => Settings::localLoginEnabled() && $user->hasPassword(),
            'minimum'   => Config::int('auth.password_min_length', 12),
            'error'     => $error,
        ], $status);
    }
}
