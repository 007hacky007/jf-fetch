<?php

declare(strict_types=1);

use App\Infra\Auth;
use App\Infra\Db;
use App\Infra\Http;
use App\Infra\ProviderSecrets;
use App\Providers\VideoProvider;
use App\Providers\WebshareProvider;

header('Content-Type: application/json');

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

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
if ($query === '') {
    Http::error(422, 'Search query parameter "q" is required.');
    exit;
}

$limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 50;
$filterProviders = isset($_GET['providers']) && is_array($_GET['providers']) ? array_map('strval', $_GET['providers']) : null;

$rows = Db::run('SELECT * FROM providers WHERE enabled = 1 ORDER BY name ASC')->fetchAll();
if ($rows === false) {
    $rows = [];
}

$results = [];
$errors = [];

foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }

    $key = (string) $row['key'];
    if ($filterProviders !== null && !in_array($key, $filterProviders, true)) {
        continue;
    }

    try {
        $provider = buildProvider($row);
        $providerResults = $provider->search($query, $limit);

        foreach ($providerResults as $item) {
            if (is_array($item)) {
                $results[] = $item + ['provider' => $key];
            }
        }
    } catch (Throwable $exception) {
        $errors[] = [
            'provider' => $key,
            'message' => $exception->getMessage(),
        ];
    }
}

Http::json(200, [
    'data' => $results,
    'errors' => $errors,
]);

/**
 * Builds a provider instance from the database row.
 *
 * @param array<string, mixed> $row
 */
function buildProvider(array $row): VideoProvider
{
    $config = ProviderSecrets::decrypt($row);

    return match ((string) $row['key']) {
        'webshare' => new WebshareProvider($config),
        default => throw new RuntimeException('Unsupported provider: ' . $row['key']),
    };
}
