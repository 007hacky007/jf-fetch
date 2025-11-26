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
$catalogId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
if ($type === '' || $catalogId === '') {
    Http::error(400, 'Catalog type and id parameters are required.');
    exit;
}

$extra = [];
foreach ($_GET as $key => $value) {
    if (in_array($key, ['type', 'id'], true)) {
        continue;
    }
    if (is_array($value)) {
        continue;
    }
    $stringValue = trim((string) $value);
    if ($stringValue === '') {
        continue;
    }
    $extra[$key] = $stringValue;
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
    $extraHash = md5(json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
    $cacheKey = sprintf('catalog:%s|%s|%s', strtolower($type), $catalogId, $extraHash);

    $config = ProviderSecrets::decrypt($providerRow);
    $provider = new KraSk2Provider($config);

    $result = KraskaCache::remember(
        $providerKey,
        $providerSignature,
        $cacheKey,
        $ttlSeconds,
        function () use ($provider, $type, $catalogId, $extra): array {
            $items = $provider->catalogItems($type, $catalogId, $extra);
            if (is_array($items) && strtolower($type) === 'movie') {
                foreach ($items as $index => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $videoId = isset($item['id']) ? (string) $item['id'] : '';
                    if ($videoId === '') {
                        continue;
                    }
                    $items[$index]['external_id'] = $provider->videoToken('movie', $videoId, [
                        'title' => $item['name'] ?? $item['title'] ?? null,
                    ]);
                }
            }

            return is_array($items) ? $items : [];
        },
        $forceRefresh
    );

    Http::json(200, [
        'data' => $result['data'],
        'cache' => KraskaCache::responseMeta($result['cache'], true),
    ]);
} catch (Throwable $exception) {
    Http::error(500, 'Failed to load catalog.', [
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
