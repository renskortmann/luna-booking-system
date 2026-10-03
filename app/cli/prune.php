<?php

declare(strict_types=1);

/**
 * Housekeeping. Safe to run from cron, daily:
 *
 *   php app/cli/prune.php
 *
 * On the TU Delft hosting that is a Plesk Scheduled Task of type "Run a PHP
 * script" pointing at this file (README.md, step 8).
 *
 * Deletes audit entries past the retention window set in the admin UI, spent
 * or expired invite links, old login-attempt records, and expired SAML
 * assertion ids.
 *
 * Everything here is a log or a spent token. Bookings and time entries are
 * business records and are NEVER pruned - do not add them by analogy, or a
 * nightly cron job will quietly destroy last year's timesheets.
 */

use Macrolab\Audit;
use Macrolab\Clock;
use Macrolab\Config;
use Macrolab\Db;
use Macrolab\Settings;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$appDir = dirname(__DIR__);
require dirname($appDir) . '/vendor/autoload.php';

Config::load($appDir . '/config.php');
date_default_timezone_set('UTC');
$db = Db::init((array) Config::get('db', []));

$retention = max(30, Settings::int('audit_retention_days'));
$audit = Audit::prune($retention);

$invites = $db->query(
    'DELETE FROM user_invites WHERE used_at IS NOT NULL OR expires_at < ?',
    [Clock::sql(Clock::now()->modify('-1 day'))]
)->rowCount();

$attempts = $db->query(
    'DELETE FROM login_attempts WHERE created_at < ?',
    [Clock::sql(Clock::now()->modify('-30 days'))]
)->rowCount();

$assertions = $db->query(
    'DELETE FROM saml_assertion_ids WHERE expires_at < ?',
    [Clock::sql()]
)->rowCount();

printf(
    "Pruned: %d audit entries (older than %d days), %d invites, %d login attempts, %d assertion ids.\n",
    $audit,
    $retention,
    $invites,
    $attempts,
    $assertions
);
