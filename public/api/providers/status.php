<?php

declare(strict_types=1);

use App\Infra\Auth;
use App\Infra\Db;
use App\Infra\Http;
use App\Infra\ProviderSecrets;
use App\Providers\VideoProvider;
use App\Providers\StatusCapableProvider;
use App\Providers\WebshareProvider;
use App\Providers\KraSkProvider;

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

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    Http::error(400, 'Provider ID is required.');
    exit;
}

$providerRow = Db::run('SELECT * FROM providers WHERE id = :id LIMIT 1', ['id' => $id])->fetch();
if ($providerRow === false) {
    Http::error(404, 'Provider not found.');
    exit;
}

try {
    $provider = buildProvider($providerRow);
} catch (Throwable $e) {
    Http::error(400, $e->getMessage());
    exit;
}

if (!$provider instanceof StatusCapableProvider) {
    Http::error(400, 'Status not implemented for this provider.');
    exit;
}

try {
    $status = $provider->status();
    Http::json(200, ['data' => $status]);
} catch (Throwable $e) {
    Http::error(500, 'Failed to retrieve status', ['detail' => $e->getMessage()]);
}

/**
 * Builds provider implementation.
 */
function buildProvider(array $providerRow): VideoProvider
{
    $config = ProviderSecrets::decrypt($providerRow);
    $key = (string) $providerRow['key'];

    return match ($key) {
        'webshare' => new WebshareProvider($config),
        'kraska' => new KraSkProvider($config),
        default => throw new RuntimeException('Unsupported provider: ' . $key),
    };
}
