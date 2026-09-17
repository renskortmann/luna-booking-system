<?php

declare(strict_types=1);

/**
 * The only PHP file inside the document root. Everything else is reached
 * through the router.
 *
 * Two layouts are supported, because TU Delft LAMP hosting may not let us move
 * the document root:
 *   preferred   <deploy>/public_html/ + <deploy>/app/ + <deploy>/vendor/
 *   restricted  public_html/index.php + public_html/app/ + public_html/vendor/
 */
$appDir = is_dir(dirname(__DIR__) . '/app') ? dirname(__DIR__) . '/app' : __DIR__ . '/app';
$rootDir = dirname($appDir);

$autoload = $rootDir . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Dependencies are not installed.\n"
        . "Run 'composer install --no-dev --optimize-autoloader' locally and upload the vendor/ directory.\n";
    exit;
}

require $autoload;

\Macrolab\Bootstrap::init($appDir . '/config.php');

/** @var \Macrolab\Router $router */
$router = require $appDir . '/routes.php';

\Macrolab\Bootstrap::run($router, \Macrolab\Http\Request::fromGlobals());
