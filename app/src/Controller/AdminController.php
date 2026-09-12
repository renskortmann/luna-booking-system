<?php

declare(strict_types=1);

namespace Luna\Controller;

use Luna\Actor;
use Luna\AdminAuth;
use Luna\Audit;
use Luna\Auth;
use Luna\BookingException;
use Luna\BookingService;
use Luna\Bookings;
use Luna\Clock;
use Luna\Config;
use Luna\Csrf;
use Luna\Db;
use Luna\Environment;
use Luna\Http\HttpException;
use Luna\Http\Request;
use Luna\Http\Response;
use Luna\Invite;
use Luna\Migrator;
use Luna\Password;
use Luna\Qr;
use Luna\RateLimit;
use Luna\Resources;
use Luna\Session;
use Luna\Settings;
use Luna\Users;
use Luna\View;
use RuntimeException;

/**
 * The administrator's side of the system: the allowlist, every booking, the
 * booking rules and the audit log.
 *
 * The admin signs in here with a password and a one-time code, never through
 * TU Delft SSO, so the lab keeps a way in that does not depend on a service
 * outside the lab.
 */
final class AdminController
{
    // ------------------------------------------------------------------ login

    public function login(Request $request): Response
    {
        if (Auth::isAdmin()) {
            return Response::redirect('/admin');
        }

        if (!AdminAuth::exists()) {
            return View::page('admin/no_account', ['title' => 'No administrator yet'], 503);
        }

        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            $username = $request->post('username', '') ?? '';
            $password = (string) ($request->post['password'] ?? '');

            RateLimit::assertAllowed('admin:' . strtolower($username));

            $account = AdminAuth::verifyPassword($username, $password);

            if ($account === null) {
                $error = 'Incorrect username or password.';
            } else {
                // The password alone establishes nothing: the session is only
                // marked as "owes a one-time code".
                Auth::setAdminPending((int) $account['id']);

                return Response::redirect('/admin/login/2fa');
            }
        }

        return View::page('admin/login', [
            'title'    => 'Administrator sign-in',
            'username' => $request->post('username', '') ?? '',
            'error'    => $error,
        ], $error !== null ? 400 : 200);
    }

    public function twoFactor(Request $request): Response
    {
        if (Auth::isAdmin()) {
            return Response::redirect('/admin');
        }

        $adminId = Auth::adminPendingId();

        if ($adminId === null) {
            return Response::redirect('/admin/login');
        }

        $account = AdminAuth::findById($adminId);

        if ($account === null) {
            Auth::logout();

            return Response::redirect('/admin/login');
        }

        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            $code = (string) ($request->post['code'] ?? '');
            $username = (string) $account['username'];

            RateLimit::assertAllowed('admin:' . $username);

            $ok = AdminAuth::verifyTotp($adminId, $code)
                || AdminAuth::verifyRecoveryCode($adminId, $code);

            if ($ok) {
                AdminAuth::markTotpConfirmed($adminId);
                Auth::completeAdminLogin($adminId, $username);
                RateLimit::record('admin:' . $username, true);
                RateLimit::clear('admin:' . $username);
                Audit::log('admin_login', 'admin', $adminId, ['username' => $username],
                    actorType: 'admin', actorLabel: 'admin:' . $username);

                return Response::redirect('/admin');
            }

            $error = 'That code is not valid. Check your authenticator app, or use a recovery code.';
        }

        return View::page('admin/two_factor', [
            'title' => 'One-time code',
            'error' => $error,
        ], $error !== null ? 400 : 200);
    }

    public function logout(Request $request): Response
    {
        Csrf::verify($request);
        Auth::logout();

        return Response::redirect('/admin/login');
    }

    // -------------------------------------------------------------- dashboard

    public function dashboard(Request $request): Response
    {
        Auth::requireAdmin();

        $resource = Resources::primary();
        $today = Bookings::inWindow(
            (int) $resource['id'],
            Clock::now()->setTimezone(Clock::displayZone())->setTime(0, 0)->setTimezone(Clock::utc()),
            Clock::now()->setTimezone(Clock::displayZone())->setTime(0, 0)->modify('+2 days')->setTimezone(Clock::utc()),
        );

        return View::page('admin/dashboard', [
            'title'         => 'Administration',
            'resource'      => $resource,
            'upcoming'      => $today,
            'userCount'     => (int) Db::get()->value('SELECT COUNT(*) FROM users'),
            'suspended'     => (int) Db::get()->value('SELECT COUNT(*) FROM users WHERE status = "suspended"'),
            'noPassword'    => (int) Db::get()->value('SELECT COUNT(*) FROM users WHERE password_hash IS NULL'),
            'authMode'      => Settings::authMode(),
            'recoveryLeft'  => AdminAuth::countUnusedRecoveryCodes((int) (Auth::adminId() ?? 0)),
            'passwordAlgo'  => Password::algorithm(),
        ]);
    }

    // --------------------------------------------------------------- allowlist

    public function users(Request $request): Response
    {
        Auth::requireAdmin();

        $inviteLink = null;
        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            try {
                $inviteLink = $this->handleUserAction($request);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }

            if ($error === null && $inviteLink === null) {
                return Response::redirect('/admin/users');
            }
        }

        return View::page('admin/users', [
            'title'      => 'Who may sign in',
            'users'      => Users::listAll(),
            'inviteLink' => $inviteLink,
            'error'      => $error,
            'authMode'   => Settings::authMode(),
        ], $error !== null ? 400 : 200);
    }

    /**
     * @return string|null an invite link to show once, when one was issued
     */
    private function handleUserAction(Request $request): ?string
    {
        $action = $request->post('action', '') ?? '';

        if ($action === 'add') {
            $netid = Users::normaliseNetid($request->post('netid', '') ?? '');

            if (!Users::isValidNetid($netid)) {
                throw new RuntimeException('That does not look like a netID.');
            }

            if (Users::findByNetid($netid) !== null) {
                throw new RuntimeException('"' . $netid . '" is already on the list.');
            }

            $user = Users::create($netid, $request->post('display_name'), $request->post('note'));
            Audit::log('user_added', 'user', $user->id, ['netid' => $netid]);

            // Straight into an invite link, because an account with no password
            // and no link is of no use to anyone.
            $token = Invite::issue($user->id, Invite::PURPOSE_SETUP);
            Session::flash('success', 'Added ' . $netid . '. Send them the link below.');

            return Invite::urlFor($token);
        }

        $userId = (int) ($request->post('user_id', '0') ?? '0');
        $user = $userId > 0 ? Users::findById($userId) : null;

        if ($user === null) {
            throw new RuntimeException('That user no longer exists.');
        }

        switch ($action) {
            case 'invite':
                $token = Invite::issue($user->id,
                    $user->hasPassword() ? Invite::PURPOSE_RESET : Invite::PURPOSE_SETUP);
                Session::flash('success',
                    'New link for ' . $user->netid . '. Any earlier link no longer works.');

                return Invite::urlFor($token);

            case 'suspend':
                Users::setStatus($user->id, 'suspended');
                Audit::log('user_suspended', 'user', $user->id, ['netid' => $user->netid]);
                Session::flash('success', $user->netid . ' can no longer sign in.');

                return null;

            case 'reinstate':
                Users::setStatus($user->id, 'approved');
                Audit::log('user_reinstated', 'user', $user->id, ['netid' => $user->netid]);
                Session::flash('success', $user->netid . ' can sign in again.');

                return null;

            case 'update':
                Users::updateProfile($user->id, $request->post('display_name'), $request->post('note'));
                Audit::log('user_updated', 'user', $user->id, ['netid' => $user->netid]);

                return null;

            case 'delete':
                // Bookings name their owner, so an account with history is
                // suspended rather than deleted.
                if (Users::countBookings($user->id) > 0) {
                    throw new RuntimeException(
                        $user->netid . ' has bookings on record. Suspend the account instead of deleting it, '
                        . 'or delete those bookings first.'
                    );
                }

                Users::delete($user->id);
                Audit::log('user_deleted', 'user', null, ['netid' => $user->netid]);
                Session::flash('success', $user->netid . ' has been removed.');

                return null;

            default:
                throw new RuntimeException('Unknown action.');
        }
    }

    // ---------------------------------------------------------------- bookings

    public function bookings(Request $request): Response
    {
        Auth::requireAdmin();

        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            try {
                $this->handleBookingAction($request);

                return Response::redirect('/admin/bookings');
            } catch (BookingException $e) {
                $error = implode(' ', $e->errors);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return View::page('admin/bookings', [
            'title'    => 'All bookings',
            'bookings' => Bookings::recent(Resources::primaryId(), 300, true),
            'users'    => Users::listAll(),
            'error'    => $error,
        ], $error !== null ? 400 : 200);
    }

    private function handleBookingAction(Request $request): void
    {
        $actor = Actor::forAdmin();
        $action = $request->post('action', '') ?? '';

        if ($action === 'create') {
            $start = Clock::parseInstant($request->post('start', '') ?? '');
            $end = Clock::parseInstant($request->post('end', '') ?? '');

            if ($start === null || $end === null) {
                throw new RuntimeException('Please give a start and an end time.');
            }

            $netid = $request->post('owner_netid', '') ?? '';
            $owner = Users::findByNetid($netid);

            if ($owner === null) {
                throw new RuntimeException('No user with netID "' . $netid . '" is on the allowlist.');
            }

            BookingService::create($actor, Resources::primaryId(), $start, $end,
                $request->post('purpose'), $owner->id);
            Session::flash('success', 'Booking created for ' . $owner->netid . '.');

            return;
        }

        $booking = Bookings::find((int) ($request->post('booking_id', '0') ?? '0'));

        if ($booking === null) {
            throw new RuntimeException('That booking no longer exists.');
        }

        switch ($action) {
            case 'update':
                $start = Clock::parseInstant($request->post('start', '') ?? '');
                $end = Clock::parseInstant($request->post('end', '') ?? '');

                if ($start === null || $end === null) {
                    throw new RuntimeException('Please give a start and an end time.');
                }

                BookingService::update($actor, $booking, $start, $end, $request->post('purpose'));
                Session::flash('success', 'Booking updated.');
                break;

            case 'cancel':
                BookingService::cancel($actor, $booking);
                Session::flash('success', 'Booking cancelled.');
                break;

            case 'delete':
                BookingService::delete($actor, $booking);
                Session::flash('success', 'Booking deleted.');
                break;

            default:
                throw new RuntimeException('Unknown action.');
        }
    }

    // ---------------------------------------------------------------- settings

    public function settings(Request $request): Response
    {
        Auth::requireAdmin();

        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            try {
                $this->saveSettings($request);
                Session::flash('success', 'Settings saved.');

                return Response::redirect('/admin/settings');
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return View::page('admin/settings', [
            'title'    => 'Booking rules',
            'settings' => Settings::all(),
            'error'    => $error,
        ], $error !== null ? 400 : 200);
    }

    private function saveSettings(Request $request): void
    {
        $integers = [
            'slot_minutes'                 => [5, 24 * 60],
            'min_booking_minutes'          => [5, 24 * 60],
            'max_booking_minutes'          => [5, 24 * 60],
            'max_advance_days'             => [1, 1095],
            'max_active_bookings_per_user' => [0, 100],
            'min_change_notice_minutes'    => [0, 7 * 24 * 60],
            'audit_retention_days'         => [30, 3650],
        ];

        $values = [];

        foreach ($integers as $key => [$min, $max]) {
            $raw = $request->post($key);

            if ($raw === null || !ctype_digit($raw)) {
                throw new RuntimeException('"' . $key . '" must be a whole number.');
            }

            $value = (int) $raw;

            if ($value < $min || $value > $max) {
                throw new RuntimeException('"' . $key . '" must be between ' . $min . ' and ' . $max . '.');
            }

            $values[$key] = (string) $value;
        }

        if ((int) $values['min_booking_minutes'] > (int) $values['max_booking_minutes']) {
            throw new RuntimeException('The shortest booking cannot be longer than the longest booking.');
        }

        foreach (['open_time', 'close_time'] as $key) {
            $raw = $request->post($key, '') ?? '';

            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw) !== 1) {
                throw new RuntimeException('"' . $key . '" must be a time such as 08:00.');
            }

            $values[$key] = $raw;
        }

        if ($values['open_time'] >= $values['close_time']) {
            throw new RuntimeException('The opening time must be earlier than the closing time.');
        }

        $days = [];
        foreach ((array) ($request->post['open_days'] ?? []) as $day) {
            if (is_scalar($day) && (int) $day >= 1 && (int) $day <= 7) {
                $days[] = (int) $day;
            }
        }

        if ($days === []) {
            throw new RuntimeException('Please open at least one day of the week.');
        }

        sort($days);
        $values['open_days'] = implode(',', array_unique($days));
        $values['allow_booking_in_past'] = ($request->post['allow_booking_in_past'] ?? '') !== '' ? '1' : '0';

        $mode = $request->post('auth_mode', 'local') ?? 'local';

        if (!in_array($mode, ['local', 'saml', 'both'], true)) {
            throw new RuntimeException('Unknown sign-in mode.');
        }

        // Stage 2 is not wired up yet; refusing here prevents locking every
        // lab member out by selecting a mode the code cannot honour.
        if ($mode !== 'local' && !Config::get('saml.profiles')) {
            throw new RuntimeException(
                'TU Delft SSO is not configured yet, so only "local" can be selected. '
                . 'See docs/ICT-REQUEST.md.'
            );
        }

        $values['auth_mode'] = $mode;

        $before = Settings::all();
        Settings::setMany($values);

        $changed = [];
        foreach ($values as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changed[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }

        if ($changed !== []) {
            Audit::log('settings_changed', 'settings', null, $changed);
        }
    }

    // ------------------------------------------------------------------- audit

    public function audit(Request $request): Response
    {
        Auth::requireAdmin();

        $action = $request->query('action', '') ?? '';
        $page = max(1, (int) ($request->query('page', '1') ?? '1'));
        $perPage = 100;

        $where = '';
        $params = [];

        if ($action !== '') {
            $where = ' WHERE action = ?';
            $params[] = $action;
        }

        $total = (int) Db::get()->value('SELECT COUNT(*) FROM audit_log' . $where, $params);
        $rows = Db::get()->all(
            'SELECT id, actor_type, actor_id, actor_label, action, target_type, target_id,
                    details, ip, created_at
               FROM audit_log' . $where . '
              ORDER BY id DESC
              LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );

        return View::page('admin/audit', [
            'title'   => 'Audit log',
            'rows'    => $rows,
            'actions' => array_column(
                Db::get()->all('SELECT DISTINCT action FROM audit_log ORDER BY action'),
                'action'
            ),
            'filter'  => $action,
            'page'    => $page,
            'pages'   => max(1, (int) ceil($total / $perPage)),
            'total'   => $total,
        ]);
    }

    // ------------------------------------------------------------------ system

    /**
     * Maintenance the administrator can do without a shell: apply a migration
     * that came with an update, change their own password, re-enrol their
     * authenticator, and issue fresh recovery codes.
     *
     * This is behind the administrator sign-in, unlike /install, so it needs no
     * token and does not go away.
     */
    public function system(Request $request): Response
    {
        Auth::requireAdmin();

        $adminId = Auth::adminId();
        $migrator = new Migrator(Db::get());

        $error = null;
        $newCodes = [];
        $newTotp = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            try {
                switch ($request->post('action', '') ?? '') {
                    case 'migrate':
                        $applied = $migrator->migrate();
                        Audit::log('migrations_applied', 'system', null, ['migrations' => $applied]);
                        Session::flash('success', $applied === []
                            ? 'The schema was already up to date.'
                            : 'Applied: ' . implode(', ', $applied) . '.');

                        return Response::redirect('/admin/system');

                    case 'change_password':
                        $current = (string) ($request->post['current_password'] ?? '');
                        $account = AdminAuth::findById((int) $adminId);

                        if ($account === null
                            || !Password::verify($current, (string) $account['password_hash'])) {
                            throw new RuntimeException('Your current password is not correct.');
                        }

                        $new = (string) ($request->post['new_password'] ?? '');

                        if ($new !== (string) ($request->post['new_password_confirm'] ?? '')) {
                            throw new RuntimeException('The two new passwords do not match.');
                        }

                        AdminAuth::changePassword((int) $adminId, $new);
                        Session::flash('success', 'Your password has been changed.');

                        return Response::redirect('/admin/system');

                    case 'regenerate_codes':
                        $newCodes = AdminAuth::regenerateRecoveryCodes((int) $adminId);
                        Audit::log('admin_recovery_codes_regenerated', 'admin', $adminId);
                        break;

                    case 'reset_totp':
                        $newTotp = AdminAuth::resetTotp((int) $adminId, (string) Auth::adminUsername());
                        break;

                    default:
                        throw new RuntimeException('Unknown action.');
                }
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return View::page('admin/system', [
            'title'        => 'System',
            'checks'       => Environment::checks(),
            'applied'      => $migrator->applied(),
            'pending'      => $migrator->pending(),
            'recoveryLeft' => AdminAuth::countUnusedRecoveryCodes((int) $adminId),
            'newCodes'     => $newCodes,
            'newTotp'      => $newTotp,
            'newTotpQr'    => $newTotp === null ? null : Qr::svg($newTotp['uri']),
            'minimum'      => Config::int('auth.password_min_length', 12),
            'error'        => $error,
        ], $error !== null ? 400 : 200);
    }

    // --------------------------------------------------------------- installer

    /**
     * The browser installer: loads the schema and creates the administrator.
     *
     * TU Delft LAMP hosting gives no SSH and no SFTP - only FTP and the Plesk
     * panel - so this, not the command line, is the normal way to install.
     *
     * Two things keep it from being a way in:
     *   - it requires app.install_token, which the operator sets in
     *     app/config.php before uploading, closing the window between upload
     *     and installation;
     *   - it stops existing the moment an administrator account exists.
     */
    public function install(Request $request): Response
    {
        $token = (string) Config::get('app.install_token', '');

        if ($token === '') {
            // Nothing to compare against: say so rather than 404, because this
            // is the operator's own omission and it is not a secret.
            return View::page('admin/install_disabled', [
                'title' => 'Installer not enabled',
            ], 503);
        }

        $provided = (string) ($request->post('install_token') ?? $request->query('token') ?? '');

        if (!hash_equals($token, $provided)) {
            // Wrong or missing token: reveal nothing at all.
            throw HttpException::notFound();
        }

        $migrator = new Migrator(Db::get());

        if ($migrator->hasTable('admin_account') && AdminAuth::exists()) {
            throw HttpException::notFound();
        }

        $error = null;
        $created = null;
        $migrated = [];

        if ($request->isPost()) {
            Csrf::verify($request);

            try {
                if (Environment::anyFatal()) {
                    throw new RuntimeException(
                        'Some requirements are not met. Fix the items marked as problems below, then try again.'
                    );
                }

                $migrated = $migrator->migrate();

                $created = AdminAuth::create(
                    $request->post('username', '') ?? '',
                    (string) ($request->post['password'] ?? '')
                );
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        if ($created !== null) {
            Audit::log('installed', 'system', null, ['migrations' => $migrated],
                actorType: 'system', actorLabel: 'installer');

            return View::page('admin/installed', [
                'title'    => 'Administrator created',
                'secret'   => $created['secret'],
                'qr'       => Qr::svg($created['uri']),
                'codes'    => $created['recovery_codes'],
                'migrated' => $migrated,
            ]);
        }

        return View::page('admin/install', [
            'title'    => 'Install the booking system',
            'error'    => $error,
            'minimum'  => Config::int('auth.password_min_length', 12),
            'checks'   => Environment::checks(),
            'pending'  => $migrator->pending(),
            'applied'  => $migrator->applied(),
            'token'    => $provided,
        ], $error !== null ? 400 : 200);
    }
}
