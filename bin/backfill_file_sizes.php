#!/usr/bin/env php
<?php
declare(strict_types=1);

use App\Infra\Config;
use App\Infra\Db;

/**
 * Backfill script: Populates file_size_bytes for existing completed jobs.
 * Run once after migration 0006 to populate historical data.
 */

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'App\\';
        if (str_starts_with($class, $prefix) === false) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = $root . '/app/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    });
}

Config::boot($root . '/config');

function logInfo(string $message): void
{
    fwrite(STDOUT, '[' . date('c') . '] [INFO] ' . $message . PHP_EOL);
}

function logError(string $message): void
{
    fwrite(STDERR, '[' . date('c') . '] [ERROR] ' . $message . PHP_EOL);
}

logInfo('Starting file size backfill for completed jobs...');

// Fetch all completed jobs without file_size_bytes
$stmt = Db::run(
    "SELECT id, final_path FROM jobs WHERE status = 'completed' AND file_size_bytes IS NULL"
);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$totalJobs = count($jobs);

if ($totalJobs === 0) {
    logInfo('No jobs to backfill. All completed jobs already have file sizes.');
    exit(0);
}

logInfo(sprintf('Found %d completed jobs without file sizes. Starting backfill...', $totalJobs));

$updated = 0;
$missing = 0;

foreach ($jobs as $job) {
    $jobId = (int) $job['id'];
    $finalPath = is_string($job['final_path'] ?? null) ? (string) $job['final_path'] : null;

    if ($finalPath === null || $finalPath === '') {
        logError(sprintf('Job %d has no final_path, skipping.', $jobId));
        $missing++;
        continue;
    }

    if (!file_exists($finalPath)) {
        logError(sprintf('Job %d: File not found at %s, skipping.', $jobId, $finalPath));
        $missing++;
        continue;
    }

    $fileSize = @filesize($finalPath);
    if ($fileSize === false || !is_int($fileSize)) {
        logError(sprintf('Job %d: Failed to read file size for %s, skipping.', $jobId, $finalPath));
        $missing++;
        continue;
    }

    Db::run(
        'UPDATE jobs SET file_size_bytes = :file_size_bytes WHERE id = :id',
        [
            'file_size_bytes' => $fileSize,
            'id' => $jobId,
        ]
    );

    $updated++;
    if ($updated % 100 === 0) {
        logInfo(sprintf('Progress: %d/%d jobs updated...', $updated, $totalJobs));
    }
}

logInfo(sprintf('Backfill complete. Updated: %d, Missing/Failed: %d, Total: %d', $updated, $missing, $totalJobs));
exit(0);
