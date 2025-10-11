<?php

declare(strict_types=1);

use App\Domain\Jobs;
use App\Download\Aria2Client;
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
    Http::error(403, 'Insufficient permissions to cancel this job.');
    exit;
}

$cancelableStates = ['queued', 'starting', 'downloading', 'paused'];
if (!in_array($job['status'], $cancelableStates, true)) {
    Http::error(422, 'Job cannot be canceled in its current state.');
    exit;
}

$aria2Gid = isset($job['aria2_gid']) ? trim((string) $job['aria2_gid']) : '';
if ($aria2Gid !== '') {
    try {
        $aria2 = new Aria2Client();
        $aria2->remove($aria2Gid, true);
    } catch (Throwable $exception) {
        Http::error(502, 'Failed to cancel aria2 transfer: ' . $exception->getMessage());
        exit;
    }
}

$message = $isAdmin ? 'Canceled by administrator.' : 'Canceled by user request.';
    $timestamp = Clock::nowString();

try {
    Db::run(
        "UPDATE jobs
         SET status = 'canceled',
             error_text = :error,
             speed_bps = NULL,
             eta_seconds = NULL,
             aria2_gid = NULL,
             updated_at = :updated_at
         WHERE id = :id",
        [
            'error' => $message,
            'updated_at' => $timestamp,
            'id' => $jobId,
        ]
    );
} catch (Throwable $exception) {
    Http::error(500, 'Failed to cancel job.', ['detail' => $exception->getMessage()]);
    exit;
}

$updated = Jobs::fetchById($jobId);
if ($updated === null) {
    Http::error(500, 'Job canceled but failed to reload updated data.');
    exit;
}

Http::json(200, ['data' => Jobs::format($updated, $isAdmin)]);

Audit::record((int) $user['id'], 'job.cancel.requested', 'job', $jobId, [
    'aria2_gid' => $aria2Gid !== '' ? $aria2Gid : null,
]);
Events::notify((int) $user['id'], $jobId, 'job.cancel.requested', [
    'title' => $updated['title'] ?? null,
]);
