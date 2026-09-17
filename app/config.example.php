<?php

declare(strict_types=1);

/**
 * Macrolab website - local configuration.
 *
 * Copy this file to app/config.php and fill it in. config.php is gitignored and
 * must never be committed or served: see the deployment notes in README.md.
 *
 * Booking rules and the active authentication mode are NOT here - they live in
 * the `settings` database table and are edited by the admin in the web UI.
 * This file holds only what must exist before the database can be reached.
 */
return [
    'app' => [
        'name' => 'Macrolab website',

        // Public base URL, no trailing slash. Baked into invite links and (in
        // stage 2) the SAML entityId and ACS URL, so fix this before go-live.
        'base_url' => 'https://example.tudelft.nl/macrolab',

        // 32 random bytes, base64-encoded. Generate with:
        //   php app/cli/generate-key.php
        // Encrypts the admin TOTP secret at rest. Changing it invalidates the
        // admin's authenticator enrolment (recovery codes still work).
        'key' => '',

        // Timezone used for every date the user sees. Storage is always UTC.
        'display_timezone' => 'Europe/Amsterdam',

        // NEVER true on a public server: shows exception details in the browser.
        'debug' => false,

        // Set false only if the server genuinely has no TLS (development).
        'require_https' => true,

        /*
         * Enables the browser installer at /install?token=...
         *
         * Needed on hosting without shell access (TU Delft LAMP gives FTP and
         * the Plesk panel only), where /install is how the schema is loaded and
         * the administrator account is created. Set it to a long random string
         * BEFORE uploading this file, so that nobody who finds the address in
         * the meantime can claim the administrator account. Remove it once the
         * account exists.
         *
         * Generate one with: php app/cli/generate-key.php
         */
        'install_token' => '',
    ],

    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'macrolab',
        'user'    => '',
        'pass'    => '',
        'charset' => 'utf8mb4',
        // Optional unix socket; leave null to connect over TCP.
        'socket'  => null,
    ],

    'auth' => [
        'user_session_idle_minutes'      => 480,
        'user_session_absolute_minutes'  => 720,
        'admin_session_idle_minutes'     => 30,
        'admin_session_absolute_minutes' => 480,

        // Single-use invite / reset links.
        'invite_ttl_days'     => 7,
        'password_min_length' => 12,

        // Login throttling, applied per identifier AND per IP address.
        'lockout' => [
            'max_failures'   => 10,
            'window_minutes' => 15,
            'lock_minutes'   => 15,
        ],
    ],

    /*
     * Stage 2 - TU Delft SSO. Ignored entirely while the `auth_mode` setting is
     * 'local'. See docs/ICT-REQUEST.md for the registration request and
     * README.md for the cutover runbook.
     */
    'saml' => [
        // 'tudelft' for production, 'dev' for the Docker test IdP.
        'idp_profile' => 'tudelft',

        // Service provider key pair. Generate with:
        //   openssl req -x509 -newkey rsa:3072 -nodes -days 3650 \
        //     -keyout app/secrets/sp.key -out app/secrets/sp.crt -subj "/CN=<host>"
        'sp_cert_path' => __DIR__ . '/secrets/sp.crt',
        'sp_key_path'  => __DIR__ . '/secrets/sp.key',

        /*
         * Which assertion attribute carries which piece of identity. Each list
         * is tried in order and the first one present wins, because the exact
         * names TU Delft releases are not known until the first real login.
         * Check /admin/saml-debug after that login and correct this list; no
         * code change is needed.
         */
        'attr_map' => [
            'netid' => [
                'uid',
                'urn:mace:dir:attribute-def:uid',
                'urn:oid:0.9.2342.19200300.100.1.1',
                'sAMAccountName',
            ],
            'email' => [
                'mail',
                'urn:mace:dir:attribute-def:mail',
                'urn:oid:0.9.2342.19200300.100.1.3',
            ],
            'name' => [
                'displayName',
                'urn:mace:dir:attribute-def:displayName',
                'urn:oid:2.16.840.1.113730.3.1.241',
                'cn',
            ],
        ],

        'profiles' => [
            // Values taken from https://login.tudelft.nl/sso/saml2/idp/metadata.php
            'tudelft' => [
                'entityId'            => 'https://login.tudelft.nl/sso/saml2/idp/metadata.php',
                'singleSignOnService' => 'https://login.tudelft.nl/sso/module.php/saml/idp/singleSignOnService',
                'singleLogoutService' => 'https://login.tudelft.nl/sso/module.php/saml/idp/singleLogout',
                // Paste the IdP signing certificate here (PEM body, no headers).
                // Fetch it from the metadata URL above; do not trust a copy from
                // anywhere else.
                'x509cert'            => '',
                // ICT confirms these two; both default to the safer setting.
                'authnRequestsSigned'  => true,
                'wantMessagesSigned'   => false,
                // Unset defaults to false in php-saml, which is what enables
                // signature-wrapping attacks. Always require a signed assertion.
                'wantAssertionsSigned' => true,
            ],
            'dev' => [
                'entityId'            => 'http://localhost:8081/simplesaml/saml2/idp/metadata.php',
                'singleSignOnService' => 'http://localhost:8081/simplesaml/saml2/idp/SSOService.php',
                'singleLogoutService' => 'http://localhost:8081/simplesaml/saml2/idp/SingleLogoutService.php',
                'x509cert'            => '',
                'authnRequestsSigned' => false,
                'wantMessagesSigned'  => false,
                'wantAssertionsSigned' => true,
            ],
        ],
    ],
];
