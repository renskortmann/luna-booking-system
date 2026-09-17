<?php

declare(strict_types=1);

namespace Macrolab;

use RuntimeException;

/**
 * Authenticated encryption for the few values that must be recoverable but
 * must not be readable in a database dump - today, the admin's TOTP secret.
 *
 * The key lives in app/config.php (app.key), never in the database, so a
 * stolen dump alone does not yield the second authentication factor.
 */
final class Crypto
{
    private const SODIUM = 'S';
    private const OPENSSL = 'O';

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            return self::SODIUM . $nonce . sodium_crypto_secretbox($plaintext, $nonce, $key);
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return self::OPENSSL . $iv . $tag . $cipher;
    }

    public static function decrypt(string $blob): string
    {
        $key = self::key();
        $version = substr($blob, 0, 1);
        $body = substr($blob, 1);

        if ($version === self::SODIUM) {
            if (!function_exists('sodium_crypto_secretbox_open')) {
                throw new RuntimeException('This value was encrypted with libsodium, which is not available here.');
            }

            $nonce = substr($body, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open(substr($body, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        } elseif ($version === self::OPENSSL) {
            $iv = substr($body, 0, 12);
            $tag = substr($body, 12, 16);
            $plain = openssl_decrypt(substr($body, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        } else {
            throw new RuntimeException('Unrecognised ciphertext format.');
        }

        if ($plain === false) {
            // Either the data was tampered with, or app.key has changed.
            throw new RuntimeException(
                'Could not decrypt: the value is corrupt, or app.key in config.php has changed.'
            );
        }

        return $plain;
    }

    private static function key(): string
    {
        $encoded = (string) Config::mustGet('app.key');
        $key = base64_decode($encoded, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException(
                'app.key must be 32 random bytes, base64-encoded. Generate one with: php app/cli/generate-key.php'
            );
        }

        return $key;
    }
}
