<?php

declare(strict_types=1);

namespace App\Infra;

use App\Support\Crypto;
use App\Infra\Db;
use DateTimeImmutable;
use RuntimeException;

/**
 * Handles encryption and decryption of provider configuration payloads.
 */
final class ProviderSecrets
{
    /**
     * Encrypts a provider configuration array for storage.
     *
     * @param array<string, mixed> $config
     */
    public static function encrypt(array $config): string
    {
        $json = json_encode($config, JSON_THROW_ON_ERROR);

        return Crypto::encrypt($json, self::encryptionKey());
    }

    /**
     * Decrypts the stored configuration JSON into an associative array.
     * Transparently migrates legacy plaintext payloads by encrypting them.
     *
     * @param array<string, mixed> $row Provider row containing at least `id` and `config_json`.
     *
     * @return array<string, mixed>
     */
    public static function decrypt(array $row): array
    {
        $payload = isset($row['config_json']) ? (string) $row['config_json'] : '';
        if ($payload === '') {
            return [];
        }

        try {
            $json = Crypto::decrypt($payload, self::encryptionKey());
        } catch (RuntimeException $exception) {
            // Attempt legacy plaintext fallback; if it succeeds, re-encrypt for future reads.
            $json = $payload;
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                self::migratePlaintextConfig((int) ($row['id'] ?? 0), $json);

                return $decoded;
            }

            throw $exception;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function migratePlaintextConfig(int $providerId, string $json): void
    {
        if ($providerId <= 0) {
            return;
        }

        try {
            $encrypted = Crypto::encrypt($json, self::encryptionKey());
        } catch (RuntimeException) {
            return;
        }

        Db::run(
            'UPDATE providers SET config_json = :config, updated_at = :updated_at WHERE id = :id',
            [
                'config' => $encrypted,
                'updated_at' => (new DateTimeImmutable())->format('c'),
                'id' => $providerId,
            ]
        );
    }

    private static function encryptionKey(): string
    {
        return (string) Config::get('security.provider_secret');
    }
}
