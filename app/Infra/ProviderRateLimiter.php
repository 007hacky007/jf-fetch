<?php

declare(strict_types=1);

namespace App\Infra;

use App\Support\Clock;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Persists provider rate limit windows in the shared settings table.
 */
final class ProviderRateLimiter
{
    private const KEY_PREFIX = 'provider_rate.';

    private function __construct()
    {
    }

    /**
     * Attempts to acquire a rate-limited slot for the given provider bucket.
     * Returns null when the slot was acquired successfully, or the number of seconds
     * remaining until the slot becomes available.
     *
     * Supports two knobs:
     *  - $intervalSeconds: minimum spacing between acquisitions (classic throttle)
     *  - $options['burst_limit'] + $options['burst_window_seconds']: maximum number of
     *    acquisitions allowed inside a rolling window.
     *
     * @param array<string, mixed> $meta   Arbitrary metadata persisted for inspection.
     * @param array<string, mixed> $options Additional limiter options (burst_limit, burst_window_seconds).
     */
    public static function acquire(string $providerKey, string $bucket, int $intervalSeconds, array $meta = [], array $options = []): ?int
    {
        $providerKey = trim(strtolower($providerKey));
        $bucket = trim(strtolower($bucket));
        if ($providerKey === '' || $bucket === '') {
            throw new RuntimeException('Provider key and bucket are required for rate limiting.');
        }
        if ($intervalSeconds < 0) {
            throw new RuntimeException('Rate limit interval cannot be negative.');
        }

        $burstLimit = null;
        if (isset($options['burst_limit'])) {
            $burstLimit = (int) $options['burst_limit'];
            if ($burstLimit <= 0) {
                $burstLimit = null;
            }
        }

        $burstWindowSeconds = null;
        if (isset($options['burst_window_seconds'])) {
            $burstWindowSeconds = (int) $options['burst_window_seconds'];
            if ($burstWindowSeconds <= 0) {
                $burstWindowSeconds = null;
            }
        }

        if ($burstLimit === null || $burstWindowSeconds === null) {
            $burstLimit = null;
            $burstWindowSeconds = null;
        }

        $settingKey = self::key($providerKey, $bucket);
        $now = time();
        $nowIso = Clock::nowString();

        return Db::transaction(static function () use ($settingKey, $providerKey, $bucket, $intervalSeconds, $burstLimit, $burstWindowSeconds, $meta, $now, $nowIso) {
            $row = null;
            try {
                $statement = Db::run('SELECT value FROM settings WHERE key = :key LIMIT 1', ['key' => $settingKey]);
                $row = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable) {
                $row = null;
            }

            $lastRun = 0;
            $windowStart = 0;
            $windowCount = 0;
            if (is_array($row) && isset($row['value']) && is_string($row['value'])) {
                $decoded = json_decode($row['value'], true);
                if (is_array($decoded)) {
                    if (isset($decoded['last_run_unix'])) {
                        $lastRun = (int) $decoded['last_run_unix'];
                    }
                    if (isset($decoded['window_start_unix'])) {
                        $windowStart = (int) $decoded['window_start_unix'];
                    }
                    if (isset($decoded['window_count'])) {
                        $windowCount = (int) $decoded['window_count'];
                    }
                }
            }

            if ($lastRun > 0) {
                $elapsed = $now - $lastRun;
                if ($elapsed < $intervalSeconds) {
                    return max(1, $intervalSeconds - $elapsed);
                }
            }

            if ($burstLimit !== null && $burstWindowSeconds !== null) {
                if ($windowStart === 0 || ($now - $windowStart) >= $burstWindowSeconds) {
                    $windowStart = $now;
                    $windowCount = 0;
                }

                if ($windowCount >= $burstLimit) {
                    return max(1, ($windowStart + $burstWindowSeconds) - $now);
                }
            }

            if ($burstLimit !== null && $burstWindowSeconds !== null) {
                $windowCount++;
            } else {
                $windowStart = 0;
                $windowCount = 0;
            }

            $record = [
                'provider' => $providerKey,
                'bucket' => $bucket,
                'interval_seconds' => $intervalSeconds,
                'last_run_unix' => $now,
                'last_run' => $nowIso,
                'meta' => $meta,
            ];

            if ($burstLimit !== null && $burstWindowSeconds !== null) {
                $record['burst_limit'] = $burstLimit;
                $record['burst_window_seconds'] = $burstWindowSeconds;
                $record['window_start_unix'] = $windowStart;
                $record['window_count'] = $windowCount;
            }

            $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                return null;
            }

            Db::run(
                'INSERT INTO settings (key, value, type, updated_at) VALUES (:key, :value, :type, :updated_at)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, type = excluded.type, updated_at = excluded.updated_at',
                [
                    'key' => $settingKey,
                    'value' => $encoded,
                    'type' => 'string',
                    'updated_at' => $nowIso,
                ]
            );

            return null;
        });
    }

    /**
     * Returns metadata about current rate limit windows.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function inspect(?string $providerKey = null): array
    {
        $prefix = self::KEY_PREFIX;
        $filters = [];
        $params = [];

        if ($providerKey !== null && $providerKey !== '') {
            $providerKey = strtolower(trim($providerKey));
            $filters[] = 'key LIKE :key';
            $params['key'] = $prefix . $providerKey . '.%';
        } else {
            $filters[] = 'key LIKE :prefix';
            $params['prefix'] = $prefix . '%';
        }

        try {
            $sql = 'SELECT key, value FROM settings WHERE ' . implode(' AND ', $filters);
            $statement = Db::run($sql, $params);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }

        $now = time();
        $result = [];

        foreach ($rows as $row) {
            if (!isset($row['key'], $row['value']) || !is_string($row['key']) || !is_string($row['value'])) {
                continue;
            }

            $decoded = json_decode($row['value'], true);
            if (!is_array($decoded)) {
                continue;
            }

            $decoded['provider'] = $decoded['provider'] ?? self::providerFromKey($row['key']);
            $decoded['bucket'] = $decoded['bucket'] ?? self::bucketFromKey($row['key']);
            if (isset($decoded['last_run_unix'])) {
                $decoded['retry_after_seconds'] = max(0, ($decoded['last_run_unix'] + ($decoded['interval_seconds'] ?? 0)) - $now);
            }
            if (isset($decoded['window_start_unix'], $decoded['burst_window_seconds'])) {
                $decoded['burst_window_retry_after_seconds'] = max(0, ($decoded['window_start_unix'] + ($decoded['burst_window_seconds'] ?? 0)) - $now);
            }

            $result[] = $decoded;
        }

        return $result;
    }

    public static function clear(string $providerKey, ?string $bucket = null): void
    {
        $providerKey = strtolower(trim($providerKey));
        if ($providerKey === '') {
            return;
        }

        $params = [];
        if ($bucket !== null && $bucket !== '') {
            $bucket = strtolower(trim($bucket));
            $params['key'] = self::key($providerKey, $bucket);
            $sql = 'DELETE FROM settings WHERE key = :key';
        } else {
            $params['prefix'] = self::KEY_PREFIX . $providerKey . '.%';
            $sql = 'DELETE FROM settings WHERE key LIKE :prefix';
        }

        try {
            Db::run($sql, $params);
        } catch (Throwable) {
            // Best-effort cleanup.
        }
    }

    private static function key(string $providerKey, string $bucket): string
    {
        return self::KEY_PREFIX . $providerKey . '.' . $bucket;
    }

    private static function providerFromKey(string $settingKey): string
    {
        $stripped = str_starts_with($settingKey, self::KEY_PREFIX)
            ? substr($settingKey, strlen(self::KEY_PREFIX))
            : $settingKey;
        $parts = explode('.', $stripped, 2);
        return strtolower($parts[0] ?? '');
    }

    private static function bucketFromKey(string $settingKey): string
    {
        $stripped = str_starts_with($settingKey, self::KEY_PREFIX)
            ? substr($settingKey, strlen(self::KEY_PREFIX))
            : $settingKey;
        $parts = explode('.', $stripped, 2);
        return strtolower($parts[1] ?? 'default');
    }
}
