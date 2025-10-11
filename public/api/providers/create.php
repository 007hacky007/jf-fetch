<?php

declare(strict_types=1);

use App\Infra\Audit;
use App\Infra\Auth;
use App\Infra\Db;
use App\Infra\Http;
use App\Infra\ProviderSecrets;
use App\Providers\ProviderConfig;

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

try {
    $payload = Http::readJsonBody();
} catch (RuntimeException $exception) {
    Http::error(400, $exception->getMessage());
    exit;
}

$key = isset($payload['key']) ? trim((string) $payload['key']) : '';
$name = isset($payload['name']) ? trim((string) $payload['name']) : '';
$enabled = isset($payload['enabled']) ? (bool) $payload['enabled'] : true;
$config = $payload['config'] ?? [];

if ($key === '' || $name === '') {
    Http::error(422, 'Provider key and name are required.');
    exit;
}

if (!is_array($config)) {
    Http::error(422, 'Provider config must be an object.');
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
        'INSERT INTO providers (key, name, enabled, config_json, created_at, updated_at) VALUES (:key, :name, :enabled, :config, :created_at, :updated_at)',
        [
            'key' => $key,
            'name' => $name,
            'enabled' => $enabled ? 1 : 0,
            'config' => $encryptedConfig,
            'created_at' => timestamp(),
            'updated_at' => timestamp(),
        ]
    );
} catch (Throwable $exception) {
    Http::error(500, 'Failed to create provider.', ['detail' => $exception->getMessage()]);
    exit;
}

$id = (int) Db::connection()->lastInsertId();
$provider = Db::run('SELECT * FROM providers WHERE id = :id', ['id' => $id])->fetch();

$actorId = is_array($actor) && isset($actor['id']) ? (int) $actor['id'] : 0;
if ($actorId > 0) {
    Audit::record($actorId, 'provider.created', 'provider', $id, [
        'key' => $key,
        'name' => $name,
    ]);
}

Http::json(201, [
    'data' => formatProvider($provider !== false ? $provider : [
        'id' => $id,
        'key' => $key,
        'name' => $name,
        'enabled' => $enabled ? 1 : 0,
        'config_json' => $encryptedConfig,
        'created_at' => timestamp(),
        'updated_at' => timestamp(),
    ]),
]);

/**
 * Formats a provider database row for API responses.
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
 * Masks sensitive configuration values.
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

/**
 * Returns current timestamp in ISO 8601 format.
 */
function timestamp(): string
{
    return (new DateTimeImmutable())->format('c');
}
