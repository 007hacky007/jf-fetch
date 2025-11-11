<?php

declare(strict_types=1);

use App\Infra\Auth;
use App\Infra\Config;
use App\Infra\Db;
use App\Infra\Http;
use App\Infra\ProviderSecrets;
use App\Providers\KraSkProvider;
use App\Providers\RateLimitDeferredException;

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

$externalId = isset($_GET['external_id']) ? trim((string) $_GET['external_id']) : '';
if ($externalId === '') {
    Http::error(400, 'Missing external_id parameter.');
    exit;
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

    $config = ProviderSecrets::decrypt($providerRow);
    // Inject debug setting from application config
    $config['debug'] = Config::get('providers.kraska_debug_enabled');
    $provider = new KraSkProvider($config);
    $options = $provider->listDownloadOptions($externalId);

    Http::json(200, [
        'data' => $options,
    ]);
} catch (RateLimitDeferredException $exception) {
    Http::error(429, $exception->getMessage(), [
        'retry_after' => $exception->getRetryAfterSeconds(),
    ]);
} catch (Throwable $exception) {
    Http::error(500, 'Failed to load download options.', [
        'detail' => $exception->getMessage(),
    ]);
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
