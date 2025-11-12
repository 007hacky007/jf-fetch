<?php

declare(strict_types=1);

use App\Infra\Auth;
use App\Infra\Config;
use App\Infra\Db;
use App\Infra\Http;
use App\Infra\ProviderSecrets;
use App\Infra\ProviderPause;
use App\Providers\VideoProvider;
use App\Providers\StatusCapableProvider;
use App\Providers\WebshareProvider;
use App\Providers\KraSkProvider;

// Unified provider status endpoint with 24h caching.
// Response shape: { data: [ { provider: string, ... } ], fetched_at: ISO8601, cached: bool }

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Http::error(405, 'Method not allowed');
    exit;
}

try {
    Auth::boot();
    Auth::requireRole('admin');
} catch (RuntimeException $exception) {
    Http::error(403, $exception->getMessage());
    exit;
}

$forceRefresh = isset($_GET['refresh']) && (($_GET['refresh'] === '1') || (strtolower((string) $_GET['refresh']) === 'true'));
$cacheKey = 'provider_status_cache';
$cacheTtlSeconds = 86400; // 24 hours
$now = time();

$cachedRow = Db::run('SELECT key, value, updated_at FROM settings WHERE key = :k LIMIT 1', ['k' => $cacheKey])->fetch();
$cachedPayload = null;
if ($cachedRow !== false && isset($cachedRow['value']) && is_string($cachedRow['value'])) {
    $decoded = json_decode($cachedRow['value'], true);
    if (is_array($decoded) && isset($decoded['fetched_at']) && isset($decoded['data']) && is_array($decoded['data'])) {
        $fetchedAtTs = strtotime((string) $decoded['fetched_at']);
        if ($fetchedAtTs !== false && ($now - $fetchedAtTs) < $cacheTtlSeconds) {
            $cachedPayload = $decoded;
        }
    }
}

if ($cachedPayload !== null && !$forceRefresh) {
    Http::json(200, [
        'data' => $cachedPayload['data'],
        'fetched_at' => $cachedPayload['fetched_at'],
        'cached' => true,
    ]);
    exit;
}

// Build fresh statuses
$providerRows = Db::run('SELECT * FROM providers ORDER BY id')->fetchAll();
$statuses = [];
 $pauseMap = [];
foreach (ProviderPause::active() as $paused) {
    $key = is_string($paused['provider'] ?? null) ? (string) $paused['provider'] : null;
    if ($key !== null && $key !== '') {
        $pauseMap[$key] = $paused;
    }
}
if (is_array($providerRows)) {
    foreach ($providerRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        try {
            $provider = buildProvider($row);
            if ($provider instanceof StatusCapableProvider) {
                $status = $provider->status();
            } else {
                $status = [
                    'provider' => (string) $row['key'],
                    'authenticated' => false,
                    'error' => 'Status not implemented',
                ];
            }
        } catch (Throwable $e) {
            $status = [
                'provider' => isset($row['key']) ? (string) $row['key'] : 'unknown',
                'authenticated' => false,
                'error' => $e->getMessage(),
            ];
        }

        $key = isset($status['provider']) ? (string) $status['provider'] : null;
        if ($key !== null && isset($pauseMap[$key])) {
            $status['paused'] = true;
            $status['pause'] = $pauseMap[$key];
        } else {
            $status['paused'] = $status['paused'] ?? false;
        }

        $statuses[] = $status;
    }
}

$payload = [
    'data' => $statuses,
    'fetched_at' => gmdate('c'),
];

// Persist cache snapshot (best effort)
try {
    Db::run('INSERT INTO settings (key, value, type, updated_at) VALUES (:k, :v, :t, :u)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value, type = excluded.type, updated_at = excluded.updated_at', [
        'k' => $cacheKey,
        'v' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        't' => 'string',
        'u' => gmdate('c'),
    ]);
} catch (Throwable $e) {
    // ignore cache persistence errors
}

Http::json(200, $payload + ['cached' => false]);

/**
 * @param array<string,mixed> $providerRow
 */
function buildProvider(array $providerRow): VideoProvider
{
    $config = ProviderSecrets::decrypt($providerRow);
    $key = (string) $providerRow['key'];
    
    // Inject debug setting from application config for kraska provider
    if ($key === 'kraska') {
        $config['debug'] = Config::get('providers.kraska_debug_enabled');
    }

    return match ($key) {
        'webshare' => new WebshareProvider($config),
        'kraska' => new KraSkProvider($config),
        default => throw new RuntimeException('Unsupported provider: ' . $key),
    };
}
