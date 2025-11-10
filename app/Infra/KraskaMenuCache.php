<?php

declare(strict_types=1);

namespace App\Infra;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Lightweight SQLite-backed cache for Kra.sk menu payloads.
 */
final class KraskaMenuCache
{
    public const DEFAULT_TTL_SECONDS = 604800;

    private const TABLE_NAME = 'kraska_menu_cache';

    private static ?PDO $pdo = null;

    private static ?string $customPath = null;

    /**
     * Attempts to locate a cached payload for the given provider/path combination.
     *
     * @return array{data: array<string,mixed>, fetched_at: string, fetched_ts: int}|null
     */
    public static function get(string $providerKey, string $providerSignature, string $path, int $ttlSeconds): ?array
    {
        if ($ttlSeconds <= 0) {
            self::remove($providerKey, $path);

            return null;
        }

        try {
            $pdo = self::connection();
        } catch (Throwable $exception) {
            self::resetConnection();

            return null;
        }

        $statement = $pdo->prepare(
            'SELECT payload_json, fetched_at, fetched_ts, provider_signature
             FROM ' . self::TABLE_NAME . '
             WHERE provider_key = :provider_key AND path = :path
             LIMIT 1'
        );
        $statement->execute([
            'provider_key' => $providerKey,
            'path' => $path,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        if (($row['provider_signature'] ?? '') !== $providerSignature) {
            self::remove($providerKey, $path);

            return null;
        }

        $fetchedTs = (int) ($row['fetched_ts'] ?? 0);
        if ($fetchedTs <= 0) {
            self::remove($providerKey, $path);

            return null;
        }

        if ((time() - $fetchedTs) > $ttlSeconds) {
            self::remove($providerKey, $path);

            return null;
        }

        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            self::remove($providerKey, $path);

            return null;
        }

        return [
            'data' => $payload,
            'fetched_at' => (string) ($row['fetched_at'] ?? ''),
            'fetched_ts' => $fetchedTs,
        ];
    }

    /**
     * Stores the payload in the cache.
     *
     * @param array<string,mixed> $payload
     *
     * @return array{fetched_at: string, fetched_ts: int}
     */
    public static function put(string $providerKey, string $providerSignature, string $path, array $payload): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode cache payload as JSON.');
        }

        $now = new DateTimeImmutable();
        $fetchedAt = $now->format(DATE_ATOM);
        $fetchedTs = $now->getTimestamp();

        $pdo = self::connection();
        $statement = $pdo->prepare(
            'INSERT INTO ' . self::TABLE_NAME . ' (provider_key, path, payload_json, fetched_at, fetched_ts, provider_signature)
             VALUES (:provider_key, :path, :payload_json, :fetched_at, :fetched_ts, :provider_signature)
             ON CONFLICT(provider_key, path)
             DO UPDATE SET
                payload_json = excluded.payload_json,
                fetched_at = excluded.fetched_at,
                fetched_ts = excluded.fetched_ts,
                provider_signature = excluded.provider_signature'
        );

        $statement->execute([
            'provider_key' => $providerKey,
            'path' => $path,
            'payload_json' => $encoded,
            'fetched_at' => $fetchedAt,
            'fetched_ts' => $fetchedTs,
            'provider_signature' => $providerSignature,
        ]);

        return [
            'fetched_at' => $fetchedAt,
            'fetched_ts' => $fetchedTs,
        ];
    }

    /**
     * Removes a specific cache entry.
     */
    public static function remove(string $providerKey, string $path): void
    {
        try {
            $pdo = self::connection();
        } catch (Throwable $exception) {
            self::resetConnection();

            return;
        }

        $statement = $pdo->prepare(
            'DELETE FROM ' . self::TABLE_NAME . ' WHERE provider_key = :provider_key AND path = :path'
        );
        $statement->execute([
            'provider_key' => $providerKey,
            'path' => $path,
        ]);
    }

    /**
     * Allows tests to override the SQLite path.
     */
    public static function useCustomPath(?string $path): void
    {
        self::$customPath = $path;
        self::resetConnection();
    }

    /**
     * Drops the active PDO connection (useful for tests/error recovery).
     */
    public static function resetConnection(): void
    {
        if (self::$pdo instanceof PDO) {
            self::$pdo = null;
        }
    }

    private static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $path = self::$customPath ?? self::defaultDatabasePath();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create cache directory: ' . $directory);
            }
        }

        try {
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('PRAGMA busy_timeout = 1000');
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to initialise cache database: ' . $exception->getMessage(), 0, $exception);
        }

        self::ensureSchema($pdo);
        self::$pdo = $pdo;

        return $pdo;
    }

    private static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE_NAME . ' (
                provider_key TEXT NOT NULL,
                path TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                fetched_at TEXT NOT NULL,
                fetched_ts INTEGER NOT NULL,
                provider_signature TEXT NOT NULL,
                PRIMARY KEY (provider_key, path)
            )'
        );

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_kraska_cache_fetched_ts ON ' . self::TABLE_NAME . ' (fetched_ts)'
        );
    }

    private static function defaultDatabasePath(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/kraska_menu.sqlite';
    }
}
