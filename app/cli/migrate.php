<?php

declare(strict_types=1);

/**
 * Applies any outstanding migration. Safe to run repeatedly.
 *
 *   php app/cli/migrate.php            apply pending migrations
 *   php app/cli/migrate.php --status   list what is applied and what is pending
 *
 * No shell on the server? Use the browser installer at /install instead - it
 * does exactly the same thing. See README.md.
 */

use Macrolab\Config;
use Macrolab\Db;
use Macrolab\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$appDir = dirname(__DIR__);
require dirname($appDir) . '/vendor/autoload.php';

Config::load($appDir . '/config.php');
date_default_timezone_set('UTC');

$migrator = new Migrator(Db::init((array) Config::get('db', [])));

if (in_array('--status', $argv, true)) {
    $applied = $migrator->applied();

    foreach ($migrator->available() as $name) {
        printf("%-40s %s\n", $name, in_array($name, $applied, true) ? 'applied' : 'PENDING');
    }

    exit(0);
}

try {
    $done = $migrator->migrate();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if ($done === []) {
    echo "Nothing to do; the schema is up to date.\n";
    exit(0);
}

foreach ($done as $name) {
    echo 'Applied ' . $name . "\n";
}

echo 'Applied ' . count($done) . " migration(s).\n";
