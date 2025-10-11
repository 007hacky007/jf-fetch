<?php

declare(strict_types=1);

use App\Domain\Jobs;
use App\Infra\Audit;
use App\Infra\Auth;
use App\Infra\Db;
use App\Infra\Events;
use App\Infra\Http;
use App\Support\Clock;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
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

$jobId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($jobId <= 0) {
    Http::error(400, 'Job ID is required.');
    exit;
}

$job = Jobs::fetchById($jobId);
if ($job === null) {
    Http::error(404, 'Job not found.');
    exit;
}

$isAdmin = Auth::isAdmin();
if (!$isAdmin && (int) $job['user_id'] !== (int) $user['id']) {
    Http::error(403, 'Insufficient permissions to update priority.');
    exit;
}

try {
    $payload = Http::readJsonBody();
} catch (RuntimeException $exception) {
    Http::error(400, $exception->getMessage());
    exit;
}

if (!array_key_exists('priority', $payload)) {
    Http::error(422, 'Priority value is required.');
    exit;
}

$priority = (int) $payload['priority'];
$priority = max(0, min(1000, $priority));

$timestamp = Clock::nowString();

try {
    Db::run(
        'UPDATE jobs SET priority = :priority, updated_at = :updated_at WHERE id = :id',
        [
            'priority' => $priority,
            'updated_at' => $timestamp,
            'id' => $jobId,
        ]
    );
} catch (Throwable $exception) {
    Http::error(500, 'Failed to update job priority.', ['detail' => $exception->getMessage()]);
    exit;
}

$updated = Jobs::fetchById($jobId);
if ($updated === null) {
    Http::error(500, 'Priority updated but failed to reload job.');
    exit;
}

Http::json(200, ['data' => Jobs::format($updated, $isAdmin)]);

Audit::record((int) $user['id'], 'job.priority.updated', 'job', $jobId, [
    'priority' => $priority,
]);
Events::notify((int) $user['id'], $jobId, 'job.priority.updated', [
    'priority' => $priority,
]);
