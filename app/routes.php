<?php

declare(strict_types=1);

/**
 * The whole route table. Read top to bottom, it is also the shortest summary
 * of what the application does.
 */

use Macrolab\Controller\AccountController;
use Macrolab\Controller\AdminController;
use Macrolab\Controller\AuthController;
use Macrolab\Controller\BookingApiController;
use Macrolab\Controller\CalendarController;
use Macrolab\Controller\HubController;
use Macrolab\Router;

$router = new Router();

// ------------------------------------------------------------------- macrolab
// The front door. Signing in lands here and picks a system from it.
$router->get('/', [HubController::class, 'show']);

// ------------------------------------------------------------------- calendar
$router->get('/booking', [CalendarController::class, 'show']);

// The JSON API the calendar talks to. Every write verifies the CSRF token and
// re-checks ownership against the stored booking.
$router->get('/api/bookings', [BookingApiController::class, 'feed']);
$router->post('/api/bookings', [BookingApiController::class, 'create']);
$router->post('/api/bookings/{id}', [BookingApiController::class, 'update']);
$router->post('/api/bookings/{id}/cancel', [BookingApiController::class, 'cancel']);

// --------------------------------------------------------------------- access
$router->form('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->form('/setup/{token}', [AuthController::class, 'setup']);
$router->form('/account', [AccountController::class, 'show']);

// Stage 2 - TU Delft SSO. The paths are reserved now so that the service
// provider metadata we register with ICT never has to change.
$router->get('/auth/saml/login', [AuthController::class, 'ssoNotEnabled']);
$router->post('/auth/saml/acs', [AuthController::class, 'ssoNotEnabled']);
$router->get('/auth/saml/sls', [AuthController::class, 'ssoNotEnabled']);
$router->get('/auth/saml/metadata', [AuthController::class, 'ssoNotEnabled']);

// ---------------------------------------------------------------------- admin
$router->form('/admin/login', [AdminController::class, 'login']);
$router->form('/admin/login/2fa', [AdminController::class, 'twoFactor']);
$router->post('/admin/logout', [AdminController::class, 'logout']);
$router->get('/admin', [AdminController::class, 'dashboard']);
$router->form('/admin/users', [AdminController::class, 'users']);
$router->form('/admin/machines', [AdminController::class, 'machines']);
$router->form('/admin/bookings', [AdminController::class, 'bookings']);
$router->form('/admin/settings', [AdminController::class, 'settings']);
$router->get('/admin/audit', [AdminController::class, 'audit']);
$router->form('/admin/system', [AdminController::class, 'system']);

// Creates the administrator account, then stops existing.
$router->form('/install', [AdminController::class, 'install']);

return $router;
