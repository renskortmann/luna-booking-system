<?php

declare(strict_types=1);

namespace Luna\Controller;

use Luna\Audit;
use Luna\Auth;
use Luna\Auth\AccessDeniedException;
use Luna\Auth\LocalProvider;
use Luna\Csrf;
use Luna\Db;
use Luna\Http\HttpException;
use Luna\Http\Request;
use Luna\Http\Response;
use Luna\Invite;
use Luna\Password;
use Luna\Session;
use Luna\Settings;
use Luna\Users;
use Luna\View;

/**
 * Signing in and out, and the invite links that let a new user set a password.
 */
final class AuthController
{
    public function login(Request $request): Response
    {
        if (Auth::user() !== null) {
            return Response::redirect('/');
        }

        if (Auth::isAdmin()) {
            return Response::redirect('/admin');
        }

        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            if (!Settings::localLoginEnabled()) {
                // auth_mode is 'saml': the password form is no longer a way in.
                Audit::log('local_login_refused_by_mode', 'user', null,
                    ['netid' => $request->post('netid', '')], actorType: 'anonymous', actorLabel: 'anonymous');

                $error = 'Password sign-in has been switched off. Please use your TU Delft netID.';
            } else {
                $identity = (new LocalProvider())->completeLogin($request);

                if ($identity === null) {
                    // One message for every kind of failure, so the page cannot
                    // be used to find out which netIDs have accounts.
                    $error = 'Incorrect netID or password.';
                } else {
                    try {
                        Auth::signIn($identity);

                        return Response::redirect('/');
                    } catch (AccessDeniedException $e) {
                        return View::page('denied', [
                            'title'   => 'Access not granted',
                            'message' => $e->getMessage(),
                        ], 403);
                    }
                }
            }
        }

        return View::page('login', [
            'title'      => 'Sign in',
            'netid'      => $request->post('netid', '') ?? '',
            'error'      => $error,
            'localOpen'  => Settings::localLoginEnabled(),
            'ssoOpen'    => Settings::samlLoginEnabled(),
        ], $error !== null ? 400 : 200);
    }

    /**
     * Sign out. POST only, with the session token, so that a third-party page
     * cannot log someone out by embedding a link.
     */
    public function logout(Request $request): Response
    {
        Csrf::verify($request);
        Auth::logout();
        Session::flash('info', 'You have been signed out.');

        return Response::redirect('/login');
    }

    /**
     * Set a password from a single-use invite link.
     *
     * An unknown, spent or expired token all give the same answer: there is
     * nothing to learn here from the difference.
     */
    public function setup(Request $request, string $token): Response
    {
        $invite = Invite::resolve($token);

        if ($invite === null) {
            return View::page('setup_invalid', [
                'title' => 'This link cannot be used',
            ], 410);
        }

        $user = Users::findById((int) $invite['user_id']);

        if ($user === null || !$user->isApproved()) {
            return View::page('setup_invalid', [
                'title' => 'This link cannot be used',
            ], 410);
        }

        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            $password = (string) ($request->post['password'] ?? '');
            $confirm = (string) ($request->post['password_confirm'] ?? '');

            if ($password !== $confirm) {
                $error = 'The two passwords do not match.';
            } else {
                $error = Password::policyError($password, $user->netid);
            }

            if ($error === null) {
                $hash = Password::hash($password);

                // One transaction: the token is spent exactly when the password
                // is written, so a crash cannot leave a used link with no
                // password or a working link with one.
                Db::get()->transaction(static function () use ($user, $hash, $invite): void {
                    Users::setPasswordHash($user->id, $hash);
                    Invite::consume((int) $invite['id']);
                });

                Audit::log('password_set_from_invite', 'user', $user->id,
                    ['purpose' => $invite['purpose']],
                    actorType: 'user', actorId: $user->id, actorLabel: $user->netid);

                Session::flash('success', 'Your password has been set. Please sign in.');

                return Response::redirect('/login');
            }
        }

        return View::page('setup', [
            'title'    => 'Choose a password',
            'netid'    => $user->netid,
            'purpose'  => (string) $invite['purpose'],
            'token'    => $token,
            'error'    => $error,
            'minimum'  => \Luna\Config::int('auth.password_min_length', 12),
        ], $error !== null ? 400 : 200);
    }

    /** Placeholder until stage 2: the SSO routes exist but are not wired yet. */
    public function ssoNotEnabled(Request $request): Response
    {
        throw HttpException::notFound(
            'TU Delft SSO is not enabled on this installation yet.'
        );
    }
}
