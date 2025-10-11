<?php

declare(strict_types=1);

use App\Infra\Audit;
use App\Infra\Auth;
use App\Infra\Db;
use App\Infra\Http;
use App\Infra\ProviderSecrets;
use App\Providers\ProviderConfig;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
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

$existing = Db::run('SELECT * FROM providers WHERE id = :id LIMIT 1', ['id' => $id])->fetch();
if ($existing === false) {
    Http::error(404, 'Provider not found.');
    exit;
}

try {
    $payload = Http::readJsonBody();
} catch (RuntimeException $exception) {
    Http::error(400, $exception->getMessage());
    exit;
}

$key = array_key_exists('key', $payload) ? trim((string) $payload['key']) : (string) $existing['key'];
$name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : (string) $existing['name'];
$enabled = array_key_exists('enabled', $payload) ? (bool) $payload['enabled'] : (bool) $existing['enabled'];

$config = $payload['config'] ?? ProviderSecrets::decrypt($existing);
if (!is_array($config)) {
    Http::error(422, 'Provider config must be an object.');
    exit;
}

if ($key === '' || $name === '') {
    Http::error(422, 'Provider key and name are required.');
    exit;
}

try {
    $config = ProviderConfig::prepare($key, $config);
} catch (RuntimeException $exception) {
    Http::error(422, $exception->getMessage());
    exit;
}

try {
    $encryptedConfig = ProviderSecrets::encrypt($config);
} catch (Throwable $exception) {
    Http::error(500, 'Failed to encrypt provider configuration.', ['detail' => $exception->getMessage()]);
    exit;
}

try {
    Db::run(
        'UPDATE providers SET key = :key, name = :name, enabled = :enabled, config_json = :config, updated_at = :updated_at WHERE id = :id',
        [
            'key' => $key,
            'name' => $name,
            'enabled' => $enabled ? 1 : 0,
            'config' => $encryptedConfig,
            'updated_at' => (new DateTimeImmutable())->format('c'),
            'id' => $id,
        ]
    );
} catch (Throwable $exception) {
    Http::error(500, 'Failed to update provider.', ['detail' => $exception->getMessage()]);
    exit;
}

$row = Db::run('SELECT * FROM providers WHERE id = :id', ['id' => $id])->fetch();

$actorId = is_array($actor) && isset($actor['id']) ? (int) $actor['id'] : 0;
if ($actorId > 0) {
    Audit::record($actorId, 'provider.updated', 'provider', $id, [
        'key' => $key,
        'enabled' => $enabled,
    ]);
}

Http::json(200, [
    'data' => formatProvider($row !== false ? $row : array_merge($existing, [
        'key' => $key,
        'name' => $name,
        'enabled' => $enabled ? 1 : 0,
        'config_json' => $encryptedConfig,
    ])),
]);

/**
 * Formats a provider for API output while masking secrets.
 *
 * @param array<string, mixed> $row
 *
 * @return array<string, mixed>
 */
function formatProvider(array $row): array
{
    $config = ProviderSecrets::decrypt($row);

    return [
        'id' => (int) $row['id'],
        'key' => (string) $row['key'],
        'name' => (string) $row['name'],
        'enabled' => (bool) $row['enabled'],
        'config' => maskSensitiveValues($config),
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
    ];
}

/**
 * Masks sensitive configuration entries.
 *
 * @param array<string, mixed> $config
 *
 * @return array<string, mixed>
 */
function maskSensitiveValues(array $config): array
{
    $sensitive = ['password', 'secret', 'token', 'wst', 'api_key'];

    foreach ($config as $key => $value) {
        if (in_array(strtolower((string) $key), $sensitive, true)) {
            $config[$key] = $value !== null && $value !== '' ? '••••••' : $value;
        }
    }

    return $config;
}
