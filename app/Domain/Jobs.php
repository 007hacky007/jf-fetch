<?php

declare(strict_types=1);

namespace App\Domain;

use App\Infra\Db;
use App\Support\Clock;
use PDO;
use RuntimeException;
use Throwable;

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

        $statusOrder = "CASE jobs.status\n                WHEN 'downloading' THEN 0\n                WHEN 'starting' THEN 1\n                WHEN 'paused' THEN 2\n                WHEN 'queued' THEN 3\n                WHEN 'completed' THEN 4\n                WHEN 'failed' THEN 5\n                WHEN 'canceled' THEN 6\n                WHEN 'deleted' THEN 7\n                ELSE 8\n            END";

        $statement = Db::run(
                "SELECT jobs.*,\n                    providers.key AS provider_key,\n                    providers.name AS provider_name,\n                    users.name AS user_name,\n                    users.email AS user_email\n             FROM jobs\n             INNER JOIN providers ON providers.id = jobs.provider_id\n             INNER JOIN users ON users.id = jobs.user_id\n             $where\n             ORDER BY $statusOrder ASC, jobs.priority ASC, jobs.position ASC, jobs.created_at ASC, jobs.id ASC",
            $params
        );

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows !== false ? $rows : [];
    }

    /**
     * Paged listing variant matching UI ordering (status weight, then priority/position/created_at).
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

        // Match UI ordering: active downloads first, then priority/position/created_at/id for stability.
        $statusOrder = "CASE jobs.status\n                WHEN 'downloading' THEN 0\n                WHEN 'starting' THEN 1\n                WHEN 'paused' THEN 2\n                WHEN 'queued' THEN 3\n                WHEN 'completed' THEN 4\n                WHEN 'failed' THEN 5\n                WHEN 'canceled' THEN 6\n                WHEN 'deleted' THEN 7\n                ELSE 8\n            END";

    $sql = "SELECT jobs.*,\n                    providers.key AS provider_key,\n                    providers.name AS provider_name,\n                    users.name AS user_name,\n                    users.email AS user_email\n             FROM jobs\n             INNER JOIN providers ON providers.id = jobs.provider_id\n             INNER JOIN users ON users.id = jobs.user_id\n             $where\n             ORDER BY $statusOrder ASC, jobs.priority ASC, jobs.position ASC, jobs.created_at ASC, jobs.id ASC\n             LIMIT :limit OFFSET :offset";

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

        // File size (bytes) preferring cached value in database
        if (isset($row['file_size_bytes']) && is_numeric($row['file_size_bytes'])) {
            $payload['file_size_bytes'] = (int) $row['file_size_bytes'];
        } elseif (isset($row['final_path']) && is_string($row['final_path']) && $row['final_path'] !== '' && is_file($row['final_path'])) {
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

        $metadata = self::decodeMetadata($row['metadata_json'] ?? null);
        if ($metadata !== null) {
            $payload['metadata'] = $metadata;
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

    private static function decodeMetadata(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Aggregates statistics about jobs and downloaded data.
     * Uses SQL SUM to aggregate file sizes from the database for optimal performance.
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

        // Aggregate bytes using SQL SUM for completed jobs
        $bytesSql = "SELECT COALESCE(SUM(file_size_bytes), 0) FROM jobs $where" . ($where ? ' AND' : ' WHERE') . " status = 'completed'";
        $bytesStmt = Db::run($bytesSql, $params);
        $totalBytes = $bytesStmt->fetchColumn();
        $stats['total_bytes_downloaded'] = $totalBytes !== false ? (int) $totalBytes : 0;

        // Aggregate duration for completed jobs
        $durationSql = "SELECT created_at, updated_at FROM jobs $where" . ($where ? ' AND' : ' WHERE') . " status = 'completed'";
        $durationStmt = Db::run($durationSql, $params);
        $durationRows = $durationStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totalDurationSeconds = 0;
        foreach ($durationRows as $row) {
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
