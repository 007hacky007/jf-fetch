<?php

declare(strict_types=1);

namespace App\Infra;

use App\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Lightweight helper tracking temporary provider backoff windows in the settings store.
 */
final class ProviderBackoff
{
    private const KEY_PREFIX = 'provider_backoff.';

    private function __construct()
    {
    }

    /**
     * Persists (or updates) a provider backoff window.
     *
     * @param array<string, mixed> $payload
     */
    public static function set(string $providerKey, int $retryAtEpoch, array $payload = []): void
    {
        $retryAtEpoch = max(0, $retryAtEpoch);
        $nowIso = Clock::nowString();
        $record = [
            'provider' => $providerKey,
            'provider_label' => $payload['provider_label'] ?? ucfirst($providerKey),
            'reason' => $payload['reason'] ?? null,
            'message' => $payload['message'] ?? null,
            'detected_at' => $payload['detected_at'] ?? $nowIso,
            'retry_at' => $payload['retry_at'] ?? self::formatIso($retryAtEpoch),
            'retry_at_unix' => $retryAtEpoch,
            'retry_after_seconds' => (int) ($payload['retry_after_seconds'] ?? max(0, $retryAtEpoch - time())),
            'job' => $payload['job'] ?? null,
            'error' => $payload['error'] ?? null,
        ];
        $record['retry_in_seconds'] = max(0, $retryAtEpoch - time());

        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        try {
            Db::run(
                'INSERT INTO settings (key, value, type, updated_at) VALUES (:key, :value, :type, :updated_at)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, type = excluded.type, updated_at = excluded.updated_at',
                [
                    'key' => self::KEY_PREFIX . $providerKey,
                    'value' => $json,
                    'type' => 'string',
                    'updated_at' => $nowIso,
                ]
            );
        } catch (Throwable) {
            // Silently ignore persistence errors (best-effort tracking only).
        }
    }

    public static function clear(string $providerKey): void
    {
        try {
            Db::run('DELETE FROM settings WHERE key = :key', ['key' => self::KEY_PREFIX . $providerKey]);
        } catch (Throwable) {
            // Ignore missing settings table / other persistence issues.
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function active(?string $providerKey = null): array
    {
        $now = time();
        $rows = [];

        try {
            if ($providerKey !== null) {
                $statement = Db::run('SELECT key, value FROM settings WHERE key = :key LIMIT 1', ['key' => self::KEY_PREFIX . $providerKey]);
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                $statement = Db::run('SELECT key, value FROM settings WHERE key LIKE :prefix', ['prefix' => self::KEY_PREFIX . '%']);
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable) {
            return [];
        }

        $active = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['key'], $row['value']) || !is_string($row['value'])) {
                continue;
            }

            $provider = self::providerKeyFromSettingKey((string) $row['key']);
            $decoded = json_decode($row['value'], true);
            if (!is_array($decoded)) {
                self::clear($provider);
                continue;
            }

            $retryAt = isset($decoded['retry_at_unix']) ? (int) $decoded['retry_at_unix'] : null;
            if ($retryAt === null || $retryAt <= $now) {
                self::clear($provider);
                continue;
            }

            $decoded['provider'] = $decoded['provider'] ?? $provider;
            $decoded['provider_label'] = $decoded['provider_label'] ?? ucfirst($provider);
            $decoded['retry_at'] = $decoded['retry_at'] ?? self::formatIso($retryAt);
            $decoded['retry_at_unix'] = $retryAt;
            $decoded['retry_in_seconds'] = max(0, $retryAt - $now);

            $active[] = $decoded;
        }

        usort(
            $active,
            static fn(array $a, array $b): int => ($a['retry_at_unix'] ?? PHP_INT_MAX) <=> ($b['retry_at_unix'] ?? PHP_INT_MAX)
        );

        return $active;
    }

    private static function providerKeyFromSettingKey(string $value): string
    {
        return str_starts_with($value, self::KEY_PREFIX) ? substr($value, strlen(self::KEY_PREFIX)) : $value;
    }

    private static function formatIso(int $epoch): string
    {
        $dateTime = DateTimeImmutable::createFromFormat('U', (string) $epoch, new DateTimeZone('UTC'));
        if ($dateTime === false) {
            $dateTime = new DateTimeImmutable('@' . max(0, $epoch), new DateTimeZone('UTC'));
        }

        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
