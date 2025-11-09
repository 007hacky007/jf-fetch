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
    Http::error(403, 'Insufficient permissions to retry this job.');
    exit;
}

if ($job['status'] !== 'failed') {
    Http::error(422, 'Only failed jobs can be retried.');
    exit;
}

// Reset the job back to queued state so scheduler can pick it up again.
$timestamp = Clock::nowString();
try {
    Db::run(
        "UPDATE jobs
         SET status = 'queued',
             progress = 0,
             speed_bps = NULL,
             eta_seconds = NULL,
             aria2_gid = NULL,
             error_text = NULL,
             updated_at = :updated_at
         WHERE id = :id",
        [
            'updated_at' => $timestamp,
            'id' => $jobId,
        ]
    );
} catch (Throwable $exception) {
    Http::error(500, 'Failed to retry job.', ['detail' => $exception->getMessage()]);
    exit;
}

$updated = Jobs::fetchById($jobId);
if ($updated === null) {
    Http::error(500, 'Job retried but failed to reload updated data.');
    exit;
}

Http::json(200, ['data' => Jobs::format($updated, $isAdmin)]);

// Audit & event notifications
Audit::record((int) $user['id'], 'job.retry.requested', 'job', $jobId, [
    'previous_error' => $job['error_text'] ?? null,
]);
Events::notify((int) $user['id'], $jobId, 'job.queued', [
    'title' => $updated['title'] ?? null,
]);
