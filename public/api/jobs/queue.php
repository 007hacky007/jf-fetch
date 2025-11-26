<?php

declare(strict_types=1);

use App\Domain\Jobs;
use App\Domain\JobQueueWriter;
use App\Infra\Audit;
use App\Infra\Auth;
use App\Infra\Db;
use App\Infra\Events;
use App\Infra\Http;
use App\Support\Clock;

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
    Http::error(422, 'At least one queue item must be provided.');
    exit;
}

$options = $payload['options'] ?? [];
$globalCategory = null;
if (is_array($options) && isset($options['category'])) {
    $globalCategory = trim((string) $options['category']);
    if ($globalCategory === '') {
        $globalCategory = null;
    }
}

$providerKeys = [];
foreach ($items as $index => $item) {
    if (!is_array($item)) {
        Http::error(422, sprintf('Item at index %d must be an object.', $index));
        exit;
    }

    $providerKey = isset($item['provider']) ? trim((string) $item['provider']) : '';
    $externalId = isset($item['external_id']) ? trim((string) $item['external_id']) : '';

    if ($providerKey === '') {
        Http::error(422, sprintf('Item at index %d is missing provider key.', $index));
        exit;
    }

    if ($externalId === '') {
        Http::error(422, sprintf('Item at index %d is missing external identifier.', $index));
        exit;
    }

    $providerKeys[] = $providerKey;
}

$providerKeys = array_values(array_unique($providerKeys));

$providers = fetchProvidersByKeys($providerKeys);
foreach ($providerKeys as $key) {
    if (!isset($providers[$key])) {
        Http::error(422, sprintf('Provider "%s" is not configured.', $key));
        exit;
    }

    if ((int) $providers[$key]['enabled'] !== 1) {
        Http::error(422, sprintf('Provider "%s" is disabled.', $key));
        exit;
    }
}

$createdJobs = [];

try {
    $createdJobs = JobQueueWriter::insertJobs($items, $currentUser, $providers, $globalCategory);
} catch (Throwable $exception) {
    Http::error(500, 'Failed to queue jobs.', ['detail' => $exception->getMessage()]);
    exit;
}

$rows = [];
foreach ($createdJobs as $jobId) {
    $row = Jobs::fetchById($jobId);
    if ($row !== null) {
        $rows[] = $row;
        Audit::record((int) $currentUser['id'], 'job.queued', 'job', $jobId, [
            'provider_id' => (int) $row['provider_id'],
            'title' => $row['title'] ?? null,
            'category' => $row['category'] ?? null,
        ]);
        Events::notify((int) $currentUser['id'], $jobId, 'job.queued', [
            'title' => $row['title'] ?? null,
        ]);
    }
}

$isAdmin = Auth::isAdmin();
$data = array_map(static fn (array $row): array => Jobs::format($row, $isAdmin), $rows);

Http::json(201, ['data' => $data]);

/**
 * Fetch providers keyed by their unique key identifier.
 *
 * @param array<int, string> $keys
 *
 * @return array<string, array<string, mixed>>
 */
function fetchProvidersByKeys(array $keys): array
{
    if ($keys === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $statement = Db::run(
        "SELECT * FROM providers WHERE key IN ($placeholders)",
        $keys
    );

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === false) {
        return [];
    }

    $map = [];
    foreach ($rows as $row) {
        $map[(string) $row['key']] = $row;
    }

    return $map;
}
    Http::error(500, 'Failed to queue jobs.', ['detail' => $exception->getMessage()]);
