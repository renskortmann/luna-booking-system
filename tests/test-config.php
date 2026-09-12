<?php

declare(strict_types=1);

/**
 * Configuration for the tests that need a database, built from environment
 * variables so no credentials live in the repository.
 *
 *   export LUNA_TEST_DB_NAME=luna_test
 *   export LUNA_TEST_DB_USER=luna_test
 *   export LUNA_TEST_DB_PASS=secret
 */
return [
    'app' => [
        'name'             => 'LUNA OD6 Booking (test)',
        'base_url'         => 'https://example.test',
        'key'              => base64_encode(str_repeat('t', 32)),
        'display_timezone' => 'Europe/Amsterdam',
        'debug'            => true,
        'require_https'    => false,
    ],
    'db' => [
        'host'    => getenv('LUNA_TEST_DB_HOST') ?: '127.0.0.1',
        'port'    => (int) (getenv('LUNA_TEST_DB_PORT') ?: 3306),
        'name'    => getenv('LUNA_TEST_DB_NAME') ?: '',
        'user'    => getenv('LUNA_TEST_DB_USER') ?: 'root',
        'pass'    => getenv('LUNA_TEST_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
        'socket'  => getenv('LUNA_TEST_DB_SOCKET') ?: null,
    ],
    'auth' => [
        'password_min_length'            => 12,
        'invite_ttl_days'                => 7,
        'lockout'                        => ['max_failures' => 10, 'window_minutes' => 15, 'lock_minutes' => 15],
        'user_session_idle_minutes'      => 480,
        'user_session_absolute_minutes'  => 720,
        'admin_session_idle_minutes'     => 30,
        'admin_session_absolute_minutes' => 480,
    ],
];
