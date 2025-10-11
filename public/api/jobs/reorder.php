<?php

declare(strict_types=1);

use App\Domain\Jobs;
use App\Infra\Audit;
use App\Infra\Auth;
use App\Infra\Events;
use App\Infra\Http;

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

$user = Auth::user();
if ($user === null) {
    Http::error(401, 'Authentication required.');
    exit;
}

try {
    $payload = Http::readJsonBody();
} catch (RuntimeException $exception) {
    Http::error(400, $exception->getMessage());
    exit;
}

$order = $payload['order'] ?? null;
if (!is_array($order) || $order === []) {
    Http::error(422, 'Order array is required.');
    exit;
}

$orderedIds = [];
foreach ($order as $value) {
    if (!is_numeric($value)) {
        Http::error(422, 'Order array must contain numeric job IDs.');
        exit;
    }

    $orderedIds[] = (int) $value;
}

try {
    Jobs::reorder($orderedIds, Auth::isAdmin(), (int) $user['id']);
} catch (RuntimeException $exception) {
    Http::error(422, $exception->getMessage());
    exit;
} catch (Throwable $exception) {
    Http::error(500, 'Failed to reorder jobs.', ['detail' => $exception->getMessage()]);
    exit;
}

Http::json(204, []);

$actorId = (int) $user['id'];
Audit::record($actorId, 'job.reordered', 'job', null, ['order' => $orderedIds]);
Events::notify($actorId, null, 'job.reordered', ['order' => $orderedIds]);
