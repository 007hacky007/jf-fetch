<?php

declare(strict_types=1);

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

$user = Auth::user();
if ($user === null) {
    Http::error(401, 'Authentication required.');
    exit;
}

$isAdmin = Auth::isAdmin();
$userId = (int) $user['id'];

$mineOnly = false;
if (!$isAdmin) {
    $mineOnly = true;
} elseif (isset($_GET['mine_only'])) {
    $rawMineOnly = strtolower(trim((string) $_GET['mine_only']));
    $mineOnly = $rawMineOnly === '1' || $rawMineOnly === 'true';
}

$where = "status = 'queued'";
$params = [];
if ($mineOnly) {
    $where .= ' AND user_id = :user_id';
    $params['user_id'] = $userId;
}

try {
    $jobsStmt = Db::run("SELECT id, user_id, title FROM jobs WHERE {$where}", $params);
    $jobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($jobs === false || $jobs === []) {
        Http::json(200, [
            'data' => [
                'canceled_count' => 0,
                'message' => 'No queued jobs found to cancel.',
            ],
        ]);
        exit;
    }

    $jobIds = [];
    $jobOwners = [];
    foreach ($jobs as $row) {
        if (!is_array($row)) {
            continue;
        }
        $jobId = isset($row['id']) ? (int) $row['id'] : 0;
        if ($jobId <= 0) {
            continue;
        }
        $jobIds[] = $jobId;
        $jobOwners[$jobId] = (int) ($row['user_id'] ?? 0);
    }

    if ($jobIds === []) {
        Http::json(200, [
            'data' => [
                'canceled_count' => 0,
                'message' => 'No queued jobs found to cancel.',
            ],
        ]);
        exit;
    }

    $timestamp = Clock::nowString();
    $actorLabel = $isAdmin ? 'administrator' : 'user';
    $displayName = isset($user['email']) && is_string($user['email']) && $user['email'] !== ''
        ? $user['email']
        : ($user['name'] ?? $actorLabel);
    $message = sprintf('Bulk canceled by %s.', $displayName);

    $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
    $updateParams = array_merge([$message, $timestamp], $jobIds);

    $updateStmt = Db::run(
        "UPDATE jobs
         SET status = 'canceled',
             error_text = ?,
             speed_bps = NULL,
             eta_seconds = NULL,
             aria2_gid = NULL,
             tmp_path = NULL,
             progress = 0,
             updated_at = ?
         WHERE id IN ({$placeholders})",
        $updateParams
    );

    $canceledCount = (int) $updateStmt->rowCount();

    foreach ($jobIds as $jobId) {
        $ownerId = $jobOwners[$jobId] ?? $userId;
        if ($ownerId <= 0) {
            $ownerId = $userId;
        }
        Events::notify($ownerId, $jobId, 'job.cancel.requested', [
            'bulk' => true,
            'actor_id' => $userId,
        ]);
    }

    Audit::record($userId, 'jobs.cancel.bulk', 'jobs', null, [
        'canceled_count' => $canceledCount,
        'mine_only' => $mineOnly,
        'job_ids' => $jobIds,
    ]);

    Http::json(200, [
        'data' => [
            'canceled_count' => $canceledCount,
            'message' => sprintf('Canceled %d queued job%s.', $canceledCount, $canceledCount === 1 ? '' : 's'),
        ],
    ]);
} catch (Throwable $exception) {
    Http::error(500, 'Failed to cancel jobs.', [
        'detail' => $exception->getMessage(),
    ]);
}
