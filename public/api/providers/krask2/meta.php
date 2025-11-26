<?php

declare(strict_types=1);

use App\Infra\Auth;
use App\Infra\Config;
use App\Infra\Db;
use App\Infra\Http;
use App\Infra\KraskaCache;
use App\Infra\ProviderSecrets;
use App\Providers\KraSk2Provider;
use PDO;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

Config::boot(dirname(__DIR__, 4) . '/config');

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
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

$type = isset($_GET['type']) ? strtolower(trim((string) $_GET['type'])) : '';
$itemId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
if ($type === '' || $itemId === '') {
    Http::error(400, 'Meta request requires type and id parameters.');
    exit;
}

try {
    $providerRow = fetchKraSk2Provider();
    if ($providerRow === null) {
        Http::error(404, 'KraSk2 provider is not configured.');
        exit;
    }

    if ((int) ($providerRow['enabled'] ?? 0) !== 1) {
        Http::error(403, 'KraSk2 provider is disabled.');
        exit;
    }

    $forceRefresh = KraskaCache::wantsForceRefresh($_GET['refresh'] ?? null);
    $ttlSeconds = KraskaCache::ttlSeconds();
    $providerKey = (string) ($providerRow['key'] ?? 'krask2');
    $providerSignature = KraskaCache::providerSignature($providerRow);
    $cacheKey = sprintf('meta:%s|%s', strtolower($type), $itemId);

    $config = ProviderSecrets::decrypt($providerRow);
    $provider = new KraSk2Provider($config);

    $result = KraskaCache::remember(
        $providerKey,
        $providerSignature,
        $cacheKey,
        $ttlSeconds,
        function () use ($provider, $type, $itemId): array {
            $meta = $provider->metaDetail($type, $itemId);
            if (isset($meta['meta']['videos']) && is_array($meta['meta']['videos'])) {
                foreach ($meta['meta']['videos'] as $index => $video) {
                    if (!is_array($video)) {
                        continue;
                    }
                    $videoId = isset($video['id']) ? (string) $video['id'] : '';
                    if ($videoId === '') {
                        continue;
                    }
                    $meta['meta']['videos'][$index]['external_id'] = $provider->videoToken('series', $videoId, [
                        'title' => $video['name'] ?? $video['title'] ?? null,
                    ]);
                }
            }

            return is_array($meta) ? $meta : [];
        },
        $forceRefresh
    );

    Http::json(200, [
        'data' => $result['data'],
        'cache' => KraskaCache::responseMeta($result['cache'], true),
    ]);
} catch (Throwable $exception) {
    Http::error(500, 'Failed to load metadata.', [
        'detail' => $exception->getMessage(),
    ]);
}

function fetchKraSk2Provider(): ?array
{
    $statement = Db::run('SELECT * FROM providers WHERE key = :key LIMIT 1', ['key' => 'krask2']);
    if ($statement === false) {
        return null;
    }

    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }

    return $row;
}
