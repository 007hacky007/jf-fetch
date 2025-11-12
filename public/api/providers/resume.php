<?php

declare(strict_types=1);

use App\Infra\Auth;
use App\Infra\Db;
use App\Infra\Http;
use App\Infra\ProviderPause;

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

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    Http::error(400, 'Invalid request payload.');
    exit;
}

$providerId = isset($input['id']) ? (int) $input['id'] : 0;
if ($providerId <= 0) {
    Http::error(400, 'Provider ID is required.');
    exit;
}

$providerRow = Db::run('SELECT id, key, name FROM providers WHERE id = :id LIMIT 1', ['id' => $providerId])->fetch();
if ($providerRow === false) {
    Http::error(404, 'Provider not found.');
    exit;
}

$providerKey = (string) $providerRow['key'];
ProviderPause::clear($providerKey);

Http::json(200, [
    'data' => [
        'provider' => $providerKey,
        'paused' => false,
    ],
]);
