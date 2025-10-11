<?php

declare(strict_types=1);

use App\Domain\Jobs;
use App\Infra\Audit;
use App\Infra\Auth;
use App\Infra\Db;
use App\Infra\Events;
use App\Infra\Http;
use App\Infra\Jellyfin;
use App\Support\Clock;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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
    Http::error(403, 'Insufficient permissions to delete this job.');
    exit;
}

if ($job['status'] === 'deleted') {
    Http::json(200, ['data' => Jobs::format($job, $isAdmin)]);
    exit;
}

if ($job['status'] !== 'completed') {
    Http::error(422, 'Only completed jobs can delete downloaded files.');
    exit;
}

$finalPath = isset($job['final_path']) ? trim((string) $job['final_path']) : '';
if ($finalPath === '') {
    Http::error(422, 'No downloaded file is associated with this job.');
    exit;
}

if (is_dir($finalPath)) {
    Http::error(422, 'The recorded path is a directory and cannot be deleted automatically.');
    exit;
}

$fileRemoved = false;
$fileMissing = false;

if (file_exists($finalPath)) {
    if (!@unlink($finalPath)) {
        Http::error(500, 'Failed to delete the downloaded file.');
        exit;
    }
    $fileRemoved = true;
} else {
    $fileMissing = true;
}

$timestamp = Clock::nowString();
$deletedBy = $isAdmin ? 'administrator' : 'owner';
$messageParts = [sprintf('File deletion requested by %s at %s.', $deletedBy, $timestamp)];

if ($fileRemoved) {
    $messageParts[] = 'Downloaded file removed successfully.';
}

if ($fileMissing) {
    $messageParts[] = 'File was already missing when the deletion was requested.';
}

$message = implode(' ', $messageParts);

try {
    Db::run(
        "UPDATE jobs
         SET status = 'deleted',
             progress = 0,
             speed_bps = NULL,
             eta_seconds = NULL,
             final_path = NULL,
             error_text = :error,
             deleted_at = :deleted_at,
             updated_at = :updated_at
         WHERE id = :id",
        [
            'error' => $message,
            'deleted_at' => $timestamp,
            'updated_at' => $timestamp,
            'id' => $jobId,
        ]
    );
} catch (Throwable $exception) {
    Http::error(500, 'Failed to update job after deletion.', ['detail' => $exception->getMessage()]);
    exit;
}

$updated = Jobs::fetchById($jobId);
if ($updated === null) {
    Http::error(500, 'Job deleted but failed to reload updated data.');
    exit;
}

Audit::record((int) $user['id'], 'job.file.deleted', 'job', $jobId, [
    'original_path' => $finalPath,
    'file_removed' => $fileRemoved,
    'file_missing' => $fileMissing,
]);
Events::notify((int) $user['id'], $jobId, 'job.deleted', [
    'title' => $updated['title'] ?? null,
]);
Jellyfin::refreshLibrary();

Http::json(200, ['data' => Jobs::format($updated, $isAdmin)]);
