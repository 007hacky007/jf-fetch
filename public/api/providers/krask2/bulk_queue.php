<?php

declare(strict_types=1);

use App\Domain\JobQueueWriter;
use App\Domain\KraSk2BulkQueue;
use App\Infra\Auth;
use App\Infra\Config;
use App\Infra\Db;
use App\Infra\Http;
use PDO;
use Throwable;

$root = dirname(__DIR__, 4);
require_once $root . '/vendor/autoload.php';

Config::boot($root . '/config');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$currentUser = Auth::user();
if ($currentUser === null) {
    Http::error(401, 'Authentication required.');
    exit;
}

try {
    $payload = Http::readJsonBody();
} catch (RuntimeException $exception) {
    Http::error(400, $exception->getMessage());
    exit;
}

$items = $payload['items'] ?? null;
if (!is_array($items) || $items === []) {
    Http::error(422, 'At least one KraSk2 item must be provided.');
    exit;
}

if (count($items) > 500) {
    Http::error(422, 'Please submit 500 items or fewer per request.');
    exit;
}

$provider = fetchKraSk2Provider();
if ($provider === null || (int) $provider['enabled'] !== 1) {
    Http::error(422, 'KraSk2 provider is not configured or disabled.');
    exit;
}

$normalizedItems = [];
foreach ($items as $index => $item) {
    if (!is_array($item)) {
        Http::error(422, sprintf('Item at index %d must be an object.', $index));
        exit;
    }

    $externalId = isset($item['external_id']) ? trim((string) $item['external_id']) : '';
    if ($externalId === '') {
        Http::error(422, sprintf('Item at index %d is missing external_id.', $index));
        exit;
    }

    $title = isset($item['title']) ? trim((string) $item['title']) : '';
    if ($title === '') {
        $title = 'KraSk2 item';
    }

    $metadata = JobQueueWriter::normalizeJobMetadata($item['metadata'] ?? null);

    $normalizedItems[] = [
        'provider' => 'krask2',
        'external_id' => $externalId,
        'title' => $title,
        'metadata' => $metadata,
        'category' => isset($item['category']) && $item['category'] !== '' ? (string) $item['category'] : null,
    ];
}

try {
    $taskId = KraSk2BulkQueue::enqueue((int) $currentUser['id'], $normalizedItems);
} catch (Throwable $exception) {
    Http::error(500, 'Unable to schedule KraSk2 bulk queue.', ['detail' => $exception->getMessage()]);
    exit;
}

Http::json(202, [
    'data' => [
        'task_id' => $taskId,
        'total_items' => count($normalizedItems),
    ],
]);

function fetchKraSk2Provider(): ?array
{
    $statement = Db::run('SELECT * FROM providers WHERE key = :key LIMIT 1', ['key' => 'krask2']);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}
