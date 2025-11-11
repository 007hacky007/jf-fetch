<?php

declare(strict_types=1);

use App\Infra\Auth;
use App\Infra\Config;
use App\Infra\Db;
use App\Infra\Http;
use DateTimeImmutable;
use JsonException;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

Config::boot(dirname(__DIR__, 3) . '/config');
Auth::boot();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Http::error(405, 'Method not allowed');
    exit;
}

try {
    Auth::requireUser();
} catch (RuntimeException $exception) {
    Http::error(401, $exception->getMessage());
    exit;
}

if (!Auth::isAdmin()) {
    Http::error(403, 'Administrator privileges required.');
    exit;
}

$checks = [];
$overallStatus = 'ok';

// Database connectivity check.
$dbStart = microtime(true);
try {
    Db::run('SELECT 1');
    $checks['database'] = [
        'status' => 'ok',
        'latency_ms' => round((microtime(true) - $dbStart) * 1000, 2),
        'message' => 'Database connection successful.',
    ];
} catch (Throwable $exception) {
    $checks['database'] = [
        'status' => 'error',
        'message' => $exception->getMessage(),
    ];
    $overallStatus = 'error';
}

// aria2 RPC health check (version + global stats if available)
$aria2Result = checkAria2();
$checks['aria2'] = $aria2Result;
if ($aria2Result['status'] !== 'ok') {
    $overallStatus = 'error';
}

// PHP runtime info (always ok but useful metadata).
$checks['runtime'] = [
    'status' => 'ok',
    'php_version' => PHP_VERSION,
];

Http::json(200, [
    'status' => $overallStatus,
    'checked_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'checks' => $checks,
]);

exit;

/**
 * Performs a lightweight aria2 JSON-RPC call to verify connectivity.
 *
 * @return array<string, mixed>
 */
function checkAria2(): array
{
    try {
        $endpoint = (string) Config::get('aria2.rpc_url');
        $secret = Config::has('aria2.secret') ? (string) Config::get('aria2.secret') : null;

        $params = [];
        if ($secret !== null && $secret !== '') {
            $params[] = 'token:' . $secret;
        }

        $payload = [
            'jsonrpc' => '2.0',
            'id' => uniqid('health_', true),
            'method' => 'aria2.getVersion',
            'params' => $params,
        ];

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException($error === '' ? 'aria2 RPC call failed.' : $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Unexpected HTTP status ' . $status);
        }

        /** @var array{result?:array<string,mixed>,error?:array<string,mixed>} $decoded */
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (isset($decoded['error'])) {
            $message = is_array($decoded['error']) ? ($decoded['error']['message'] ?? 'Unknown error') : 'Unknown error';

            return [
                'status' => 'error',
                'message' => 'aria2 RPC error: ' . $message,
            ];
        }

        $result = $decoded['result'] ?? [];

        return [
            'status' => 'ok',
            'version' => is_array($result) ? ($result['version'] ?? null) : null,
            'enabled_features' => is_array($result) ? ($result['enabledFeatures'] ?? []) : [],
        ];
    } catch (JsonException|Throwable $exception) {
        return [
            'status' => 'error',
            'message' => $exception->getMessage(),
        ];
    }
}
