<?php

declare(strict_types=1);

/**
 * The only PHP file inside the document root. Everything else is reached
 * through the router.
 *
 * Two layouts are supported:
 *   preferred   <deploy>/public_html/ + <deploy>/app/ + <deploy>/vendor/
 *   restricted  public_html/index.php + public_html/app/ + public_html/vendor/
 *
 * The TU Delft deployment uses the preferred one: Plesk Git deploys the
 * repository to the subscription root and the document root is set to
 * public_html, so app/ and vendor/ are not web-reachable at all. The
 * restricted layout is the fallback for hosting where the document root cannot
 * be moved (README.md, "Fallbacks"); app/ is then protected by .htaccess only.
 */
$appDir = is_dir(dirname(__DIR__) . '/app') ? dirname(__DIR__) . '/app' : __DIR__ . '/app';
$rootDir = dirname($appDir);

$autoload = $rootDir . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Dependencies are not installed.\n"
        . "On the server: Plesk -> PHP Composer -> Install (Production mode).\n"
        . "Locally: composer install.\n";
    exit;
}

require $autoload;

\Macrolab\Bootstrap::init($appDir . '/config.php');

/** @var \Macrolab\Router $router */
$router = require $appDir . '/routes.php';

\Macrolab\Bootstrap::run($router, \Macrolab\Http\Request::fromGlobals());
