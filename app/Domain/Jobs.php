<?php

declare(strict_types=1);

namespace App\Domain;

use App\Infra\Db;
use App\Support\Clock;
use PDO;
use RuntimeException;

/**
 * Domain helpers for interacting with queued download jobs.
 */
final class Jobs
{
    /**
     * Retrieves jobs for listing purposes, applying RBAC filters.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function list(bool $isAdmin, int $userId, bool $mineOnly): array
    {
        $where = '';
        $params = [];

        if (!$isAdmin || $mineOnly) {
            $where = 'WHERE jobs.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $statement = Db::run(
            "SELECT jobs.*,\n                    providers.key AS provider_key,\n                    providers.name AS provider_name,\n                    users.name AS user_name,\n                    users.email AS user_email\n             FROM jobs\n             INNER JOIN providers ON providers.id = jobs.provider_id\n             INNER JOIN users ON users.id = jobs.user_id\n             $where\n             ORDER BY jobs.priority ASC, jobs.position ASC, jobs.created_at ASC",
            $params
        );

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows !== false ? $rows : [];
    }

    /**
     * Paged listing variant matching UI ordering (newest first by created_at).
     * Returns associative array with 'rows' and 'total'.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function listPaged(bool $isAdmin, int $userId, bool $mineOnly, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $where = '';
        $params = [];

        if (!$isAdmin || $mineOnly) {
            $where = 'WHERE jobs.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        // Total count (without limit) for pagination metadata
        $countSql = "SELECT COUNT(*) AS cnt FROM jobs " . ($where !== '' ? str_replace('jobs.user_id', 'jobs.user_id', $where) : '');
        $countStmt = Db::run($countSql, $params);
        $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
        $total = $countRow !== false ? (int) ($countRow['cnt'] ?? 0) : 0;

        // UI expects newest first (created_at DESC) then priority/position for consistent queue ordering
        $sql = "SELECT jobs.*,\n                    providers.key AS provider_key,\n                    providers.name AS provider_name,\n                    users.name AS user_name,\n                    users.email AS user_email\n             FROM jobs\n             INNER JOIN providers ON providers.id = jobs.provider_id\n             INNER JOIN users ON users.id = jobs.user_id\n             $where\n             ORDER BY jobs.created_at DESC, jobs.priority ASC, jobs.position ASC, jobs.id DESC\n             LIMIT :limit OFFSET :offset";

        // Use explicit binding for limit/offset (PDO prepared statement requires int cast)
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $rowsStmt = Db::run($sql, $params);
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'rows' => $rows !== false ? $rows : [],
            'total' => $total,
        ];
    }

    /**
     * Fetches a single job row joined with provider and user metadata.
     *
     * @return array<string, mixed>|null
     */
    public static function fetchById(int $jobId): ?array
    {
        $statement = Db::run(
            "SELECT jobs.*,\n                    providers.key AS provider_key,\n                    providers.name AS provider_name,\n                    users.name AS user_name,\n                    users.email AS user_email\n             FROM jobs\n             INNER JOIN providers ON providers.id = jobs.provider_id\n             INNER JOIN users ON users.id = jobs.user_id\n             WHERE jobs.id = :id\n             LIMIT 1",
            ['id' => $jobId]
        );

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Formats a job row for API output.
     *
     * @param array<string, mixed> $row Raw database row joined with provider/user columns.
     * @param bool $includeUser Include user details in the payload.
     *
     * @return array<string, mixed>
     */
    public static function format(array $row, bool $includeUser = false): array
    {
        $payload = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'status' => (string) $row['status'],
            'progress' => (int) $row['progress'],
            'speed_bps' => isset($row['speed_bps']) ? (int) $row['speed_bps'] : null,
            'eta_seconds' => isset($row['eta_seconds']) ? (int) $row['eta_seconds'] : null,
            'priority' => (int) $row['priority'],
            'position' => (int) $row['position'],
            'category' => $row['category'] !== null ? (string) $row['category'] : null,
            'external_id' => (string) $row['external_id'],
            'user_name' => isset($row['user_name']) ? (string) $row['user_name'] : '',
            'user_email' => isset($row['user_email']) ? (string) $row['user_email'] : '',
            'provider' => [
                'id' => (int) $row['provider_id'],
                'key' => (string) ($row['provider_key'] ?? ''),
                'name' => (string) ($row['provider_name'] ?? ''),
            ],
            'error_text' => $row['error_text'] !== null ? (string) $row['error_text'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            // Expose tmp_path so UI can delete partial files for canceled jobs
            'tmp_path' => isset($row['tmp_path']) && $row['tmp_path'] !== null ? (string) $row['tmp_path'] : null,
        ];

        // File size (bytes) if final file exists
        if (isset($row['final_path']) && is_string($row['final_path']) && $row['final_path'] !== '' && is_file($row['final_path'])) {
            $size = @filesize($row['final_path']);
            if (is_int($size) && $size >= 0) {
                $payload['file_size_bytes'] = $size;
            }
        }

        // Download duration: approximate as updated_at - created_at when completed
        if (($row['status'] ?? '') === 'completed') {
            try {
                $createdTs = strtotime((string) $row['created_at']);
                $updatedTs = strtotime((string) $row['updated_at']);
                if ($createdTs !== false && $updatedTs !== false && $updatedTs >= $createdTs) {
                    $payload['download_duration_seconds'] = $updatedTs - $createdTs;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        if ($includeUser) {
            $payload['user'] = [
                'id' => (int) $row['user_id'],
                'name' => (string) ($row['user_name'] ?? ''),
                'email' => (string) ($row['user_email'] ?? ''),
            ];
        }

        if (isset($row['aria2_gid']) && $row['aria2_gid'] !== null && $row['aria2_gid'] !== '') {
            $payload['aria2_gid'] = (string) $row['aria2_gid'];
        }

        if ($row['final_path'] !== null) {
            $payload['final_path'] = (string) $row['final_path'];
        }

        if ($row['deleted_at'] !== null) {
            $payload['deleted_at'] = (string) $row['deleted_at'];
        }

        return $payload;
    }

    /**
     * Returns the next queue position number.
     */
    public static function nextPosition(): int
    {
        $statement = Db::run('SELECT COALESCE(MAX(position), 0) AS max_position FROM jobs');
        $max = $statement->fetch(PDO::FETCH_ASSOC);
        $current = is_array($max) ? (int) ($max['max_position'] ?? 0) : 0;

        return $current + 1;
    }

    /**
     * Reorders jobs according to the provided ordered list of job IDs.
     * Enforces RBAC by ensuring non-admin users can only reorder their own jobs.
     *
     * @param array<int, int> $orderedJobIds
     */
    public static function reorder(array $orderedJobIds, bool $isAdmin, int $userId): void
    {
        if ($orderedJobIds === []) {
            throw new RuntimeException('Order array must not be empty.');
        }

        $unique = array_values(array_unique(array_map('intval', $orderedJobIds)));
        if (count($unique) !== count($orderedJobIds)) {
            throw new RuntimeException('Order array must not contain duplicate job IDs.');
        }

        $placeholders = implode(',', array_fill(0, count($unique), '?'));
        $statement = Db::run(
            "SELECT id, user_id FROM jobs WHERE id IN ($placeholders)",
            $unique
        );

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === false || count($rows) !== count($unique)) {
            throw new RuntimeException('One or more jobs were not found.');
        }

        if (!$isAdmin) {
            foreach ($rows as $row) {
                if ((int) $row['user_id'] !== $userId) {
                    throw new RuntimeException('You are not allowed to reorder these jobs.');
                }
            }
        }

        Db::transaction(static function () use ($unique): void {
            $timestamp = Clock::nowString();

            foreach ($unique as $position => $jobId) {
                Db::run(
                    'UPDATE jobs SET position = :position, updated_at = :updated_at WHERE id = :id',
                    [
                        'position' => $position + 1,
                        'updated_at' => $timestamp,
                        'id' => $jobId,
                    ]
                );
            }
        });
    }

    /**
     * Fetches jobs updated after the supplied ISO timestamp for SSE streaming.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function updatedSince(string $isoTimestamp, bool $isAdmin, int $userId, int $limit = 50, ?int $afterId = null): array
    {
        $limit = max(1, min(250, $limit));
    $timestamp = $isoTimestamp !== '' ? $isoTimestamp : '1970-01-01T00:00:00.000000+00:00';

        $params = ['since' => $timestamp];
        
        if ($afterId !== null) {
            $params['after_id'] = $afterId;
            // Use >= to ensure we catch updates to the same job with slightly later timestamps
            $updatedCondition = '(jobs.updated_at > :since OR (jobs.updated_at >= :since AND jobs.id > :after_id))';
        } else {
            $updatedCondition = 'jobs.updated_at >= :since';
        }

        $conditions = [$updatedCondition];

        if (!$isAdmin) {
            $conditions[] = 'jobs.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $sql = "SELECT jobs.*,\n                    providers.key AS provider_key,\n                    providers.name AS provider_name,\n                    users.name AS user_name,\n                    users.email AS user_email\n             FROM jobs\n             INNER JOIN providers ON providers.id = jobs.provider_id\n             INNER JOIN users ON users.id = jobs.user_id\n             $where\n             ORDER BY jobs.updated_at ASC, jobs.id ASC\n             LIMIT $limit";

        $statement = Db::run($sql, $params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows !== false ? $rows : [];
    }

    /**
     * Returns distinct titles of completed (and not deleted) jobs that contain the provided search fragment.
     * Used to warn users about potential duplicate downloads during provider search.
     *
     * @return array<int, string>
     */
    public static function findExistingDownloadsMatching(string $search, int $limit = 10): array
    {
        $fragment = trim($search);
        if ($fragment === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $normalizedNeedle = self::normalizeSearchFragment($fragment);
        if ($normalizedNeedle === '') {
            return [];
        }

        $rawPattern = '%' . strtolower($fragment) . '%';
        $normalizedFragment = strtolower($fragment);
        $normalizedFragment = str_replace(['.', '_', '-', '/', '\\'], ' ', $normalizedFragment);
        $normalizedFragment = preg_replace('/\s+/', ' ', $normalizedFragment ?? '') ?? '';
        $normalizedFragment = trim($normalizedFragment);
        $normalizedPattern = $normalizedFragment !== '' ? '%' . $normalizedFragment . '%' : $rawPattern;

        $sql = 'SELECT DISTINCT title
                FROM jobs
                WHERE status = :status
                  AND (deleted_at IS NULL OR deleted_at = "")
                  AND (
                        LOWER(title) LIKE :raw_pattern
                        OR LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(title, ".", " "), "_", " "), "-", " "), "/", " "), "\\", " ")) LIKE :normalized_pattern
                    )
                ORDER BY LOWER(title) ASC
                LIMIT ' . $limit;

        $statement = Db::run($sql, [
            'status' => 'completed',
            'raw_pattern' => $rawPattern,
            'normalized_pattern' => $normalizedPattern,
        ]);

        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        if ($rows === false) {
            return [];
        }

        $titles = [];
        foreach ($rows as $row) {
            if (!is_string($row)) {
                continue;
            }
            $title = trim($row);
            if ($title === '') {
                continue;
            }
            $haystack = self::normalizeSearchFragment($title);
            if ($haystack === '' || !str_contains($haystack, $normalizedNeedle)) {
                continue;
            }
            $titles[] = $title;
        }

        return $titles;
    }

    private static function normalizeSearchFragment(string $value): string
    {
        $normalized = strtolower($value);
        $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized ?? '') ?? '';
        return $normalized;
    }

    /**
     * Aggregates statistics about jobs and downloaded data.
     * NOTE: File size aggregation reads file sizes from disk for completed jobs.
     * For large installations consider persisting file_size_bytes in the database.
     *
     * @return array<string, scalar|null>
     */
    public static function stats(bool $isAdmin, int $userId): array
    {
        $conditions = [];
        $params = [];
        if (!$isAdmin) {
            $conditions[] = 'user_id = :user_id';
            $params['user_id'] = $userId;
        }
        $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

        $baseCountsSql = [
            'total_jobs' => "SELECT COUNT(*) FROM jobs $where",
            'completed_jobs' => "SELECT COUNT(*) FROM jobs $where" . ($where ? ' AND' : ' WHERE') . " status = 'completed'",
            'active_jobs' => "SELECT COUNT(*) FROM jobs $where" . ($where ? ' AND' : ' WHERE') . " status IN ('starting','downloading')",
            'queued_jobs' => "SELECT COUNT(*) FROM jobs $where" . ($where ? ' AND' : ' WHERE') . " status = 'queued'",
            'paused_jobs' => "SELECT COUNT(*) FROM jobs $where" . ($where ? ' AND' : ' WHERE') . " status = 'paused'",
            'canceled_jobs' => "SELECT COUNT(*) FROM jobs $where" . ($where ? ' AND' : ' WHERE') . " status = 'canceled'",
            'failed_jobs' => "SELECT COUNT(*) FROM jobs $where" . ($where ? ' AND' : ' WHERE') . " status = 'failed'",
            'deleted_jobs' => "SELECT COUNT(*) FROM jobs $where" . ($where ? ' AND' : ' WHERE') . " status = 'deleted'",
        ];

        $stats = [];
        foreach ($baseCountsSql as $key => $sql) {
            $stmt = Db::run($sql, $params);
            $value = $stmt->fetchColumn();
            $stats[$key] = $value !== false ? (int) $value : 0;
        }

        // Distinct users involved in these jobs (respect RBAC)
        $distinctSql = "SELECT COUNT(DISTINCT user_id) FROM jobs $where";
        $distinctStmt = Db::run($distinctSql, $params);
        $distinctUsers = $distinctStmt->fetchColumn();
        $stats['distinct_users'] = $distinctUsers !== false ? (int) $distinctUsers : 0;

        // Aggregate bytes & duration for completed jobs
        $completedSql = "SELECT id, final_path, created_at, updated_at FROM jobs $where" . ($where ? ' AND' : ' WHERE') . " status = 'completed'";
        $completedStmt = Db::run($completedSql, $params);
        $completedRows = $completedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totalBytes = 0;
        $totalDurationSeconds = 0;
        foreach ($completedRows as $row) {
            $path = is_string($row['final_path'] ?? null) ? (string) $row['final_path'] : null;
            if ($path && is_file($path)) {
                $size = @filesize($path);
                if (is_int($size) && $size > 0) {
                    $totalBytes += $size;
                }
            }
            try {
                $createdTs = strtotime((string) $row['created_at']);
                $updatedTs = strtotime((string) $row['updated_at']);
                if ($createdTs !== false && $updatedTs !== false && $updatedTs >= $createdTs) {
                    $totalDurationSeconds += ($updatedTs - $createdTs);
                }
            } catch (\Throwable) {
                // ignore parse errors
            }
        }
        $stats['total_bytes_downloaded'] = $totalBytes;
        $stats['total_download_duration_seconds'] = $totalDurationSeconds;

        // Average download duration (seconds) for completed jobs
        $stats['avg_download_duration_seconds'] = $stats['completed_jobs'] > 0
            ? (int) floor($totalDurationSeconds / $stats['completed_jobs'])
            : null;

        // Percentage success rate
        $stats['success_rate_pct'] = $stats['total_jobs'] > 0
            ? (int) floor(($stats['completed_jobs'] / $stats['total_jobs']) * 100)
            : null;

        return $stats;
    }
}
