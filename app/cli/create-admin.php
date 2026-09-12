<?php

declare(strict_types=1);

/**
 * Creates the single administrator account, interactively.
 *
 *   php app/cli/create-admin.php
 *
 * Prints the TOTP secret and the recovery codes once. Refuses to run if an
 * administrator already exists.
 */

use Luna\AdminAuth;
use Luna\Config;
use Luna\Db;
use Luna\Password;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$appDir = dirname(__DIR__);
require dirname($appDir) . '/vendor/autoload.php';

Config::load($appDir . '/config.php');
date_default_timezone_set('UTC');
Db::init((array) Config::get('db', []));

if (AdminAuth::exists()) {
    fwrite(STDERR, "An administrator account already exists. Nothing to do.\n");
    exit(1);
}

echo "Creating the administrator account for " . Config::string('app.name') . ".\n\n";

$username = prompt('Username [admin]: ') ?: 'admin';
$minimum = Config::int('auth.password_min_length', 12);

echo "Password (at least {$minimum} characters; a few unrelated words work well).\n";
$password = promptHidden('Password: ');
$confirm = promptHidden('Password again: ');

if ($password !== $confirm) {
    fwrite(STDERR, "\nThe passwords do not match. Nothing was created.\n");
    exit(1);
}

if ($error = Password::policyError($password)) {
    fwrite(STDERR, "\n" . $error . " Nothing was created.\n");
    exit(1);
}

try {
    $created = AdminAuth::create($username, $password);
} catch (Throwable $e) {
    fwrite(STDERR, "\nFailed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nAdministrator '{$username}' created. Password hashing: " . Password::algorithm() . ".\n";
echo str_repeat('-', 72) . "\n";
echo "Add this to your authenticator app (Google Authenticator, Microsoft\n";
echo "Authenticator, Bitwarden, 1Password, Aegis - anything that does TOTP):\n\n";
echo "  Secret : " . $created['secret'] . "\n";
echo "  URI    : " . $created['uri'] . "\n\n";
echo "Recovery codes - each works once, for when the authenticator is not to\n";
echo "hand. Store them somewhere other than your password manager's same entry:\n\n";

foreach ($created['recovery_codes'] as $code) {
    echo '  ' . $code . "\n";
}

echo "\n" . str_repeat('-', 72) . "\n";
echo "This is the only time any of the above is shown.\n";

function prompt(string $label): string
{
    echo $label;

    return trim((string) fgets(STDIN));
}

/**
 * Read without echoing. Falls back to a visible prompt where stty is absent,
 * saying so rather than silently showing the password.
 */
function promptHidden(string $label): string
{
    echo $label;

    if (PHP_OS_FAMILY !== 'Windows' && shell_exec('command -v stty') !== null) {
        $previous = trim((string) shell_exec('stty -g'));
        shell_exec('stty -echo');
        $value = trim((string) fgets(STDIN));
        shell_exec('stty ' . $previous);
        echo "\n";

        return $value;
    }

    echo "\n(warning: this terminal cannot hide input - your password will be visible)\n";

    return trim((string) fgets(STDIN));
}
