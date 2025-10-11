<?php

declare(strict_types=1);

use App\Infra\Audit;
use App\Infra\Auth;
use App\Infra\Db;
use App\Infra\Http;
use App\Infra\ProviderSecrets;
use App\Providers\VideoProvider;
use App\Providers\WebshareProvider;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$actor = Auth::user();

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

$provider = buildProvider($providerRow);

$query = isset($_GET['q']) ? (string) $_GET['q'] : 'test';

try {
    $provider->search($query, 1);
    $actorId = is_array($actor) && isset($actor['id']) ? (int) $actor['id'] : 0;
    if ($actorId > 0) {
        Audit::record($actorId, 'provider.tested', 'provider', $id, ['query' => $query]);
    }

    Http::json(200, ['status' => 'ok']);
} catch (Throwable $exception) {
    $actorId = is_array($actor) && isset($actor['id']) ? (int) $actor['id'] : 0;
    if ($actorId > 0) {
        Audit::record($actorId, 'provider.test_failed', 'provider', $id, [
            'query' => $query,
            'error' => $exception->getMessage(),
        ]);
    }

    Http::error(500, 'Provider test failed.', ['detail' => $exception->getMessage()]);
}

/**
 * Builds provider implementations for testing.
 */
function buildProvider(array $providerRow): VideoProvider
{
    $config = ProviderSecrets::decrypt($providerRow);
    $key = (string) $providerRow['key'];

    return match ($key) {
        'webshare' => new WebshareProvider($config),
        default => throw new RuntimeException('Unsupported provider: ' . $key),
    };
}
