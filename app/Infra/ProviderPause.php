<?php

declare(strict_types=1);

namespace App\Infra;

use App\Support\Clock;
use PDO;
use Throwable;

/**
 * Tracks manual provider pause windows stored in the settings table.
 */
final class ProviderPause
{
    private const KEY_PREFIX = 'provider_pause.';

    private function __construct()
    {
    }

    /**
     * Stores a paused provider entry (idempotent).
     *
     * @param array<string, mixed> $payload
     */
    public static function set(string $providerKey, array $payload = []): void
    {
        $providerKey = trim($providerKey);
        if ($providerKey === '') {
            return;
        }

        $providerId = isset($payload['provider_id']) && is_numeric($payload['provider_id'])
            ? (int) $payload['provider_id']
            : self::resolveProviderId($providerKey);

        $pausedAtIso = isset($payload['paused_at']) && is_string($payload['paused_at']) && $payload['paused_at'] !== ''
            ? $payload['paused_at']
            : Clock::nowString();
        $pausedAtUnix = isset($payload['paused_at_unix']) && is_numeric($payload['paused_at_unix'])
            ? (int) $payload['paused_at_unix']
            : (strtotime($pausedAtIso) ?: time());

        $record = [
            'type' => 'paused',
            'provider' => $providerKey,
            'provider_id' => $providerId,
            'provider_label' => $payload['provider_label'] ?? ucfirst($providerKey),
            'note' => isset($payload['note']) && $payload['note'] !== '' ? (string) $payload['note'] : null,
            'paused_at' => $pausedAtIso,
            'paused_at_unix' => $pausedAtUnix,
            'paused_by' => $payload['paused_by'] ?? ($payload['paused_by_name'] ?? null),
            'paused_by_id' => isset($payload['paused_by_id']) && is_numeric($payload['paused_by_id']) ? (int) $payload['paused_by_id'] : null,
            'paused_by_email' => $payload['paused_by_email'] ?? null,
        ];

        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }

        try {
            Db::run(
                'INSERT INTO settings (key, value, type, updated_at) VALUES (:key, :value, :type, :updated_at)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, type = excluded.type, updated_at = excluded.updated_at',
                [
                    'key' => self::KEY_PREFIX . $providerKey,
                    'value' => $encoded,
                    'type' => 'string',
                    'updated_at' => Clock::nowString(),
                ]
            );
        } catch (Throwable) {
            // Persisting the pause is best-effort; ignore storage errors.
        }
    }

    public static function clear(string $providerKey): void
    {
        try {
            Db::run('DELETE FROM settings WHERE key = :key', ['key' => self::KEY_PREFIX . $providerKey]);
        } catch (Throwable) {
            // Ignore missing settings table or other persistence issues.
        }
    }

    public static function isPaused(string $providerKey): bool
    {
        return self::find($providerKey) !== null;
    }

    /**
     * Returns pause metadata for a specific provider, if any.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $providerKey): ?array
    {
        $items = self::active($providerKey);
        return $items[0] ?? null;
    }

    /**
     * Returns all active paused providers (optionally filtered by provider key).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function active(?string $providerKey = null): array
    {
        try {
            if ($providerKey !== null) {
                $statement = Db::run(
                    'SELECT key, value FROM settings WHERE key = :key LIMIT 1',
                    ['key' => self::KEY_PREFIX . $providerKey]
                );
            } else {
                $statement = Db::run(
                    'SELECT key, value FROM settings WHERE key LIKE :prefix',
                    ['prefix' => self::KEY_PREFIX . '%']
                );
            }
        } catch (Throwable) {
            return [];
        }

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $active = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['key'], $row['value']) || !is_string($row['value'])) {
                continue;
            }

            $keyProvider = self::providerKeyFromSettingKey((string) $row['key']);
            $decoded = json_decode($row['value'], true);
            if (!is_array($decoded)) {
                self::clear($keyProvider);
                continue;
            }

            $decoded['type'] = 'paused';
            $decoded['provider'] = $decoded['provider'] ?? $keyProvider;
            if (!isset($decoded['provider_id']) || !is_numeric($decoded['provider_id'])) {
                $resolvedId = self::resolveProviderId($decoded['provider']);
                if ($resolvedId !== null) {
                    $decoded['provider_id'] = $resolvedId;
                } else {
                    unset($decoded['provider_id']);
                }
            } else {
                $decoded['provider_id'] = (int) $decoded['provider_id'];
            }

            $decoded['provider_label'] = $decoded['provider_label'] ?? ucfirst($decoded['provider']);
            $decoded['note'] = isset($decoded['note']) && $decoded['note'] !== '' ? (string) $decoded['note'] : null;
            $decoded['paused_at'] = isset($decoded['paused_at']) && is_string($decoded['paused_at']) && $decoded['paused_at'] !== ''
                ? $decoded['paused_at']
                : Clock::nowString();
            if (!isset($decoded['paused_at_unix']) || !is_numeric($decoded['paused_at_unix'])) {
                $decoded['paused_at_unix'] = strtotime($decoded['paused_at']) ?: time();
            } else {
                $decoded['paused_at_unix'] = (int) $decoded['paused_at_unix'];
            }
            if (isset($decoded['paused_by_id']) && is_numeric($decoded['paused_by_id'])) {
                $decoded['paused_by_id'] = (int) $decoded['paused_by_id'];
            } else {
                $decoded['paused_by_id'] = null;
            }

            $active[] = $decoded;
        }

        usort(
            $active,
            static fn(array $a, array $b): int => strcasecmp((string) ($a['provider_label'] ?? ''), (string) ($b['provider_label'] ?? ''))
        );

        return $active;
    }

    /**
     * Returns provider IDs that are currently paused (deduplicated).
     *
     * @return array<int>
     */
    public static function providerIds(): array
    {
        $ids = [];
        foreach (self::active() as $entry) {
            if (isset($entry['provider_id']) && is_int($entry['provider_id'])) {
                $ids[] = $entry['provider_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    private static function providerKeyFromSettingKey(string $value): string
    {
        return str_starts_with($value, self::KEY_PREFIX) ? substr($value, strlen(self::KEY_PREFIX)) : $value;
    }

    private static function resolveProviderId(string $providerKey): ?int
    {
        static $cache = [];

        if (array_key_exists($providerKey, $cache)) {
            return $cache[$providerKey];
        }

        try {
            $statement = Db::run('SELECT id FROM providers WHERE key = :key LIMIT 1', ['key' => $providerKey]);
            $value = $statement->fetchColumn();
            $cache[$providerKey] = is_numeric($value) ? (int) $value : null;
        } catch (Throwable) {
            $cache[$providerKey] = null;
        }

        return $cache[$providerKey];
    }
}
