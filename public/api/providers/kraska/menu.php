<?php

declare(strict_types=1);

use App\Infra\Auth;
use App\Infra\Config;
use App\Infra\Db;
use App\Infra\Http;
use App\Infra\KraskaMenuCache;
use App\Infra\ProviderSecrets;
use App\Providers\KraSkProvider;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Http::error(405, 'Method not allowed');
    exit;
}

try {
    Auth::boot();
    Auth::requireUser();
} catch (RuntimeException $exception) {
    Http::error(401, $exception->getMessage());
    exit;
}

$path = isset($_GET['path']) ? trim((string) $_GET['path']) : '/';
if ($path === '') {
    $path = '/';
}
$forceRefresh = wantsForceRefresh($_GET['refresh'] ?? null);
$ttlSeconds = (int) Config::get('providers.kraska_menu_cache_ttl_seconds');
if ($ttlSeconds < 0) {
    $ttlSeconds = KraskaMenuCache::DEFAULT_TTL_SECONDS;
}

try {
    $providerRow = fetchKraSkProvider();
    if ($providerRow === null) {
        Http::error(404, 'Kra.sk provider is not configured.');
        exit;
    }

    if ((int) ($providerRow['enabled'] ?? 0) !== 1) {
        Http::error(403, 'Kra.sk provider is disabled.');
        exit;
    }

    $providerKey = (string) ($providerRow['key'] ?? 'kraska');
    $providerSignature = hash(
        'sha256',
        (string) ($providerRow['config_json'] ?? '') . '|' . (string) ($providerRow['updated_at'] ?? '')
    );

    $cacheHit = false;
    $result = null;
    $fetchedAt = null;
    $fetchedTs = null;

    if (!$forceRefresh) {
        try {
            $cached = KraskaMenuCache::get($providerKey, $providerSignature, $path, $ttlSeconds);
        } catch (Throwable $cacheException) {
            $cached = null;
        }

        if ($cached !== null) {
            $result = filterMenuPayload($cached['data'] ?? []);
            $fetchedAt = $cached['fetched_at'];
            $fetchedTs = (int) $cached['fetched_ts'];
            $cacheHit = true;
        }
    }

    if ($result === null) {
        $config = ProviderSecrets::decrypt($providerRow);
        // Inject debug setting from application config
        $config['debug'] = Config::get('providers.kraska_debug_enabled');
        $provider = new KraSkProvider($config);
        $result = filterMenuPayload($provider->browseMenu($path));

        $now = new \DateTimeImmutable();
        $fetchedAt = $now->format(DATE_ATOM);
        $fetchedTs = $now->getTimestamp();

        try {
            $persisted = KraskaMenuCache::put($providerKey, $providerSignature, $path, $result);
            $fetchedAt = $persisted['fetched_at'];
            $fetchedTs = (int) $persisted['fetched_ts'];
        } catch (Throwable $cacheException) {
            // Cache failures should not break the endpoint; fall back to live data only.
        }
    }

    $fetchedTs = $fetchedTs ?? time();
    $ageSeconds = max(0, time() - $fetchedTs);

    Http::json(200, [
        'data' => $result,
        'cache' => [
            'hit' => $cacheHit,
            'fetched_at' => $fetchedAt,
            'age_seconds' => $ageSeconds,
            'ttl_seconds' => $ttlSeconds,
            'refreshable' => true,
        ],
    ]);
} catch (Throwable $exception) {
    Http::error(500, 'Failed to browse Kra.sk menu.', ['detail' => $exception->getMessage()]);
}

/**
 * @return array<string,mixed>|null
 */
function fetchKraSkProvider(): ?array
{
    $statement = Db::run('SELECT * FROM providers WHERE key = :key LIMIT 1', ['key' => 'kraska']);
    if ($statement === false) {
        return null;
    }

    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }

    return $row;
}

function wantsForceRefresh(mixed $raw): bool
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

/**
 * Removes Kra.sk menu entries whose type is not supported by the UI.
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function filterMenuPayload(array $payload): array
{
    if (!isset($payload['items']) || !is_array($payload['items'])) {
        return $payload;
    }

    $disallowed = ['action', 'ldir', 'cmd'];
    $filtered = [];

    foreach ($payload['items'] as $item) {
        if (!is_array($item)) {
            continue;
        }

        $type = strtolower(trim((string) ($item['type'] ?? '')));
        if ($type !== '' && in_array($type, $disallowed, true)) {
            continue;
        }

        $filtered[] = $item;
    }

    $payload['items'] = $filtered;

    return $payload;
}
