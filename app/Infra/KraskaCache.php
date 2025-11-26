<?php

declare(strict_types=1);

namespace App\Infra;

use DateTimeImmutable;
use Throwable;

final class KraskaCache
{
    /**
     * @param callable():mixed $producer
     * @return array{data:mixed, cache:array{hit:bool,fetched_at:?string,fetched_ts:?int,ttl_seconds:int}}
     */
    public static function remember(
        string $providerKey,
        string $providerSignature,
        string $entryKey,
        int $ttlSeconds,
        callable $producer,
        bool $forceRefresh = false
    ): array {
        $cacheHit = false;
        $fetchedAt = null;
        $fetchedTs = null;
        $payload = null;

        if (!$forceRefresh && $ttlSeconds > 0) {
            try {
                $cached = KraskaMenuCache::get($providerKey, $providerSignature, $entryKey, $ttlSeconds);
            } catch (Throwable) {
                $cached = null;
            }

            if ($cached !== null) {
                $payload = $cached['data'] ?? null;
                $fetchedAt = $cached['fetched_at'] ?? null;
                $fetchedTs = isset($cached['fetched_ts']) ? (int) $cached['fetched_ts'] : null;
                $cacheHit = true;
            }
        }

        if ($payload === null) {
            $payload = $producer();
            $now = new DateTimeImmutable();
            $fetchedAt = $now->format(DATE_ATOM);
            $fetchedTs = $now->getTimestamp();

            if ($ttlSeconds > 0) {
                try {
                    $persisted = KraskaMenuCache::put($providerKey, $providerSignature, $entryKey, (array) $payload);
                    $fetchedAt = $persisted['fetched_at'] ?? $fetchedAt;
                    $fetchedTs = isset($persisted['fetched_ts']) ? (int) $persisted['fetched_ts'] : $fetchedTs;
                } catch (Throwable) {
                    // Cache write failures are non-fatal; fall back to the live fetch metadata.
                }
            }
        }

        return [
            'data' => $payload,
            'cache' => [
                'hit' => $cacheHit,
                'fetched_at' => $fetchedAt,
                'fetched_ts' => $fetchedTs,
                'ttl_seconds' => $ttlSeconds,
            ],
        ];
    }

    public static function ttlSeconds(): int
    {
        $ttlSeconds = (int) Config::get('providers.kraska_menu_cache_ttl_seconds');
        if ($ttlSeconds < 0) {
            return KraskaMenuCache::DEFAULT_TTL_SECONDS;
        }

        return $ttlSeconds;
    }

    public static function wantsForceRefresh(mixed $raw): bool
    {
        if ($raw === null) {
            return false;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw)) {
            return $raw === 1;
        }

        $value = strtolower(trim((string) $raw));
        if ($value === '') {
            return false;
        }

        return in_array($value, ['1', 'true', 'yes', 'y', 'force', 'refresh'], true);
    }

    public static function providerSignature(array $providerRow): string
    {
        return hash(
            'sha256',
            (string) ($providerRow['config_json'] ?? '') . '|' . (string) ($providerRow['updated_at'] ?? '')
        );
    }

    public static function responseMeta(array $cacheState, bool $refreshable = true): array
    {
        $fetchedTs = isset($cacheState['fetched_ts']) && $cacheState['fetched_ts'] !== null
            ? (int) $cacheState['fetched_ts']
            : null;
        $ageSeconds = $fetchedTs !== null ? max(0, time() - $fetchedTs) : null;

        return [
            'hit' => (bool) ($cacheState['hit'] ?? false),
            'fetched_at' => $cacheState['fetched_at'] ?? null,
            'age_seconds' => $ageSeconds,
            'ttl_seconds' => isset($cacheState['ttl_seconds']) ? (int) $cacheState['ttl_seconds'] : null,
            'refreshable' => $refreshable,
        ];
    }
}
