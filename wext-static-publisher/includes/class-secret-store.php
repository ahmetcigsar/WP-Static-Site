<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text data. Escape when rendering in HTML.

use RuntimeException;

final class Secret_Store
{
    public static function encrypt(string $plain_text): string
    {
        if ($plain_text === '') {
            return '';
        }

        $key = self::key();
        if (function_exists('sodium_crypto_secretbox') && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher_text = sodium_crypto_secretbox($plain_text, $nonce, $key);
            return 's1:' . base64_encode($nonce . $cipher_text);
        }

        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $cipher_text = openssl_encrypt($plain_text, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if (is_string($cipher_text)) {
                return 'o1:' . base64_encode($iv . $tag . $cipher_text);
            }
        }

        throw new RuntimeException(__('The secret could not be encrypted. Enable Sodium or OpenSSL in PHP.', 'wext-static-publisher'));
    }

    public static function decrypt(string $encrypted): string
    {
        return self::decrypt_with_key($encrypted, self::key());
    }

    public static function migrate_legacy_ciphertext(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        return self::encrypt(self::decrypt_with_key($encrypted, self::legacy_key()));
    }

    private static function decrypt_with_key(string $encrypted, string $key): string
    {
        if ($encrypted === '') {
            return '';
        }

        if (str_starts_with($encrypted, 's1:')) {
            return self::decrypt_sodium(substr($encrypted, 3), $key);
        }
        if (str_starts_with($encrypted, 'o1:')) {
            return self::decrypt_openssl(substr($encrypted, 3), $key);
        }

        throw new RuntimeException(__('The saved secret is invalid. Save the connection again.', 'wext-static-publisher'));
    }

    private static function decrypt_sodium(string $payload, string $key): string
    {
        if (! function_exists('sodium_crypto_secretbox_open') || ! defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')) {
            throw new RuntimeException(__('PHP Sodium is required to read the saved secret.', 'wext-static-publisher'));
        }

        $decoded = base64_decode($payload, true);
        if (! is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException(__('The saved secret is invalid. Save the connection again.', 'wext-static-publisher'));
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain_text = sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        if (! is_string($plain_text)) {
            throw new RuntimeException(__('The saved secret could not be decrypted. Save the connection again.', 'wext-static-publisher'));
        }
        return $plain_text;
    }

    private static function decrypt_openssl(string $payload, string $key): string
    {
        if (! function_exists('openssl_decrypt')) {
            throw new RuntimeException(__('PHP OpenSSL is required to read the saved secret.', 'wext-static-publisher'));
        }

        $decoded = base64_decode($payload, true);
        if (! is_string($decoded) || strlen($decoded) <= 28) {
            throw new RuntimeException(__('The saved secret is invalid. Save the connection again.', 'wext-static-publisher'));
        }

        $plain_text = openssl_decrypt(
            substr($decoded, 28),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            substr($decoded, 0, 12),
            substr($decoded, 12, 16)
        );
        if (! is_string($plain_text)) {
            throw new RuntimeException(__('The saved secret could not be decrypted. Save the connection again.', 'wext-static-publisher'));
        }
        return $plain_text;
    }

    private static function key(): string
    {
        return hash_hkdf('sha256', wp_salt('auth'), 32, 'wext-static-publisher-sftp');
    }

    private static function legacy_key(): string
    {
        $legacy_brand = 'rag' . 'nus';
        return hash_hkdf('sha256', wp_salt('auth'), 32, $legacy_brand . '-static-publisher-sftp');
    }
}
