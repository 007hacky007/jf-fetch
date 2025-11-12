<?php

declare(strict_types=1);

use App\Infra\Auth;
use App\Infra\Db;
use App\Infra\Http;
use App\Infra\ProviderPause;
use App\Support\Clock;

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

$note = isset($input['note']) && is_string($input['note']) ? trim($input['note']) : null;

$providerRow = Db::run('SELECT id, key, name FROM providers WHERE id = :id LIMIT 1', ['id' => $providerId])->fetch();
if ($providerRow === false) {
    Http::error(404, 'Provider not found.');
    exit;
}

$providerKey = (string) $providerRow['key'];
$providerName = (string) $providerRow['name'];

$user = Auth::user();
$payload = [
    'provider_id' => (int) $providerRow['id'],
    'provider_label' => $providerName !== '' ? $providerName : ucfirst($providerKey),
    'note' => $note,
    'paused_at' => Clock::nowString(),
];

if (is_array($user)) {
    $payload['paused_by'] = $user['name'] ?? null;
    $payload['paused_by_name'] = $user['name'] ?? null;
    $payload['paused_by_email'] = $user['email'] ?? null;
    if (isset($user['id'])) {
        $payload['paused_by_id'] = (int) $user['id'];
    }
}

ProviderPause::set($providerKey, $payload);

$record = ProviderPause::find($providerKey);

Http::json(200, [
    'data' => $record,
]);
