<?php

declare(strict_types=1);

/**
 * Prints a value for app.key in app/config.php.
 *
 * The key encrypts the admin's TOTP secret at rest, so that a stolen database
 * dump alone does not yield the second authentication factor. Keep it out of
 * version control and out of any backup that travels with the database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

echo base64_encode(random_bytes(32)), "\n";
