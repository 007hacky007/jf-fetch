<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Simple symmetric encryption helper for provider credentials.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    /**
     * Encrypts plaintext using an application secret, returning base64 payload.
     */
    public static function encrypt(string $plaintext, string $key): string
    {
        if ($plaintext === '') {
            return '';
        }

        $binaryKey = self::deriveKey($key);
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false) {
            throw new RuntimeException('Unable to determine IV length.');
        }

        $iv = random_bytes($ivLength);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $binaryKey, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false || $tag === '') {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypts ciphertext using an application secret.
     */
    public static function decrypt(string $ciphertext, string $key): string
    {
        if ($ciphertext === '') {
            return '';
        }

        $decoded = base64_decode($ciphertext, true);
        if ($decoded === false) {
            throw new RuntimeException('Encrypted payload is not valid base64.');
        }

        $binaryKey = self::deriveKey($key);
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false) {
            throw new RuntimeException('Unable to determine IV length.');
        }

        if (strlen($decoded) <= $ivLength + 16) {
            throw new RuntimeException('Encrypted payload is too short.');
        }

        $iv = substr($decoded, 0, $ivLength);
        $tag = substr($decoded, $ivLength, 16);
        $cipher = substr($decoded, $ivLength + 16);

        $plaintext = openssl_decrypt($cipher, self::CIPHER, $binaryKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed.');
        }

        return $plaintext;
    }

    private static function deriveKey(string $key): string
    {
        if ($key === '') {
            throw new RuntimeException('Encryption key is required.');
        }

        return hash('sha256', $key, true);
    }
}
