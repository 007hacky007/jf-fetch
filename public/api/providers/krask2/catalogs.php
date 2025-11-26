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

    $config = ProviderSecrets::decrypt($providerRow);
    $provider = new KraSk2Provider($config);

    $result = KraskaCache::remember(
        $providerKey,
        $providerSignature,
        'manifest',
        $ttlSeconds,
        static function () use ($provider): array {
            $manifest = $provider->manifest();
            $catalogs = is_array($manifest['catalogs'] ?? null) ? $manifest['catalogs'] : [];

            return [
                'manifest' => [
                    'id' => $manifest['id'] ?? null,
                    'name' => $manifest['name'] ?? null,
                    'version' => $manifest['version'] ?? null,
                ],
                'catalogs' => $catalogs,
            ];
        },
        $forceRefresh
    );

    Http::json(200, [
        'data' => $result['data'],
        'cache' => KraskaCache::responseMeta($result['cache'], true),
    ]);
} catch (Throwable $exception) {
    Http::error(500, 'Failed to load KraSk2 catalogs.', [
        'detail' => $exception->getMessage(),
    ]);
}

/**
 * @return array<string,mixed>|null
 */
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
