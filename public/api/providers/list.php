<?php

declare(strict_types=1);

use App\Infra\Auth;
use App\Infra\Db;
use App\Infra\Http;
use App\Infra\ProviderSecrets;

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

$statement = Db::run('SELECT id, key, name, enabled, config_json, created_at, updated_at FROM providers ORDER BY name ASC');
$rows = $statement->fetchAll();

$providers = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }

    $config = ProviderSecrets::decrypt($row);

    $providers[] = [
        'id' => (int) $row['id'],
        'key' => (string) $row['key'],
        'name' => (string) $row['name'],
        'enabled' => (bool) $row['enabled'],
        'config' => maskSensitiveValues($config),
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
    ];
}

Http::json(200, ['data' => $providers]);

/**
 * Masks sensitive values such as secrets or passwords.
 *
 * @param array<string, mixed> $config
 *
 * @return array<string, mixed>
 */
function maskSensitiveValues(array $config): array
{
    $sensitiveKeys = ['password', 'secret', 'token', 'wst', 'api_key'];

    foreach ($config as $key => $value) {
        if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
            $config[$key] = $value !== null && $value !== '' ? '••••••' : $value;
        }
    }

    return $config;
}
