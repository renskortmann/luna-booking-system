<?php

declare(strict_types=1);

/**
 * Clears the stage 1 password hashes and any outstanding invite links, after
 * the lab has switched to TU Delft SSO and is satisfied that it works.
 *
 *   php app/cli/purge-local-passwords.php          show what would happen
 *   php app/cli/purge-local-passwords.php --force  do it
 *
 * The administrator account is not touched: that sign-in is deliberately
 * independent of SSO.
 */

use Macrolab\Audit;
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

$mode = Settings::authMode();
$force = in_array('--force', $argv, true);

$withPassword = (int) $db->value('SELECT COUNT(*) FROM users WHERE password_hash IS NOT NULL');
$invites = (int) $db->value('SELECT COUNT(*) FROM user_invites WHERE used_at IS NULL');

echo "Sign-in mode: {$mode}\n";
echo "Accounts with a local password: {$withPassword}\n";
echo "Outstanding invite links: {$invites}\n\n";

if ($mode !== 'saml') {
    fwrite(STDERR, "Refusing to run: sign-in mode is '{$mode}', not 'saml'.\n"
        . "Switch to TU Delft SSO only in the admin settings first, and confirm that\n"
        . "people can actually sign in that way - otherwise this locks everyone out.\n");
    exit(1);
}

if (!$force) {
    echo "Nothing changed. Re-run with --force to clear the passwords and links.\n";
    exit(0);
}

$db->transaction(static function ($db): void {
    $db->query('UPDATE users SET password_hash = NULL, password_changed_at = NULL WHERE password_hash IS NOT NULL');
    $db->query('DELETE FROM user_invites');
});

Audit::log('local_passwords_purged', 'system', null,
    ['accounts' => $withPassword, 'invites' => $invites],
    actorType: 'system', actorLabel: 'cli');

echo "Cleared {$withPassword} password hash(es) and {$invites} invite link(s).\n";
echo "The administrator account is unchanged.\n";
