<?php

declare(strict_types=1);

use App\Domain\Jobs;
use App\Infra\Audit;
use App\Infra\Auth;
use App\Infra\Db;
use App\Infra\Events;
use App\Infra\Http;
use App\Support\Clock;

/**
 * Bulk retry failed jobs within a specified time window.
 * 
 * Query parameters:
 * - hours: Number of hours to look back (4 or 24)
 * - mine_only: Optional, if true only retry user's own jobs (non-admins always restricted to own jobs)
 */

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

$isAdmin = Auth::isAdmin();
$userId = (int) $user['id'];

// Parse time window parameter
$hours = isset($_GET['hours']) ? (int) $_GET['hours'] : 0;
if (!in_array($hours, [4, 24], true)) {
    Http::error(400, 'Invalid hours parameter. Must be 4 or 24.');
    exit;
}

// Determine if filtering to user's jobs only
$mineOnly = false;
if (!$isAdmin) {
    // Non-admins can only retry their own jobs
    $mineOnly = true;
} elseif (isset($_GET['mine_only']) && (($_GET['mine_only'] === '1') || (strtolower((string) $_GET['mine_only']) === 'true'))) {
    $mineOnly = true;
}

// Calculate cutoff timestamp
$cutoffTimestamp = new DateTimeImmutable();
$cutoffTimestamp = $cutoffTimestamp->modify("-{$hours} hours");
$cutoff = $cutoffTimestamp->format('c');

// Build query to find failed jobs within time window
$where = "status = 'failed' AND updated_at >= :cutoff";
$params = ['cutoff' => $cutoff];

if ($mineOnly) {
    $where .= " AND user_id = :user_id";
    $params['user_id'] = $userId;
}

try {
    // Fetch jobs that will be retried
    $jobsStmt = Db::run(
        "SELECT id, title, user_id, error_text FROM jobs WHERE {$where}",
        $params
    );
    $failedJobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($failedJobs === false || count($failedJobs) === 0) {
        Http::json(200, [
            'data' => [
                'retried_count' => 0,
                'message' => "No failed jobs found in the last {$hours} hours.",
            ],
        ]);
        exit;
    }

    $jobIds = array_map(fn($job) => (int) $job['id'], $failedJobs);
    
    // Reset failed jobs to queued state
    $timestamp = Clock::nowString();
    $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
    
    $updateParams = [...$jobIds, $timestamp];
    Db::run(
        "UPDATE jobs
         SET status = 'queued',
             progress = 0,
             speed_bps = NULL,
             eta_seconds = NULL,
             aria2_gid = NULL,
             error_text = NULL,
             updated_at = ?
         WHERE id IN ({$placeholders})",
        $updateParams
    );

    $retriedCount = count($jobIds);

    // Audit log
    Audit::record($userId, 'jobs.retry.bulk', 'jobs', null, [
        'hours' => $hours,
        'retried_count' => $retriedCount,
        'mine_only' => $mineOnly,
        'job_ids' => $jobIds,
    ]);

    // Send notifications for each retried job
    foreach ($failedJobs as $job) {
        if (!is_array($job)) {
            continue;
        }
        $jobUserId = (int) ($job['user_id'] ?? 0);
        $jobId = (int) ($job['id'] ?? 0);
        Events::notify($jobUserId, $jobId, 'job.queued', [
            'title' => $job['title'] ?? null,
            'bulk_retry' => true,
        ]);
    }

    Http::json(200, [
        'data' => [
            'retried_count' => $retriedCount,
            'message' => "Successfully queued {$retriedCount} failed job(s) for retry.",
        ],
    ]);
} catch (Throwable $exception) {
    Http::error(500, 'Failed to retry jobs.', [
        'detail' => $exception->getMessage(),
    ]);
}
