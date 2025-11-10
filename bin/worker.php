#!/usr/bin/env php
<?php
declare(strict_types=1);

use App\Download\Aria2Client;
use App\Infra\Audit;
use App\Infra\Config;
use App\Infra\Db;
use App\Infra\Events;
use App\Infra\Jellyfin;
use App\Support\Clock;
use RuntimeException;

/**
 * Worker loop: polls aria2 for job progress, updates the database, and
 * handles completion/failure operations such as moving files into the library.
 */

if (!function_exists('initializeAutoloader')) {
	function initializeAutoloader(string $root): void
	{
		$autoload = $root . '/vendor/autoload.php';
		if (file_exists($autoload)) {
			require_once $autoload;

			return;
		}

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
}

if (!function_exists('logInfo')) {
	function logInfo(string $message): void
	{
		fwrite(STDOUT, '[' . date('c') . '] [INFO] ' . $message . PHP_EOL);
	}
}

if (!function_exists('logError')) {
	function logError(string $message): void
	{
		fwrite(STDERR, '[' . date('c') . '] [ERROR] ' . $message . PHP_EOL);
	}
}

if (!function_exists('sleepWithLog')) {
	function sleepWithLog(int $seconds, string $message): void
	{
		logInfo($message);
		sleep($seconds);
	}
}

$root = dirname(__DIR__);
initializeAutoloader($root);

if (!defined('APP_DISABLE_DAEMONS')) {
	runWorkerLoop($root);
}

/**
 * Boots a basic PSR-4 autoloader fallback if Composer is unavailable.
 */

/**
 * Runs the worker loop until interrupted (skipped during tests).
 */
function runWorkerLoop(string $root): void
{
	Config::boot($root . '/config');

	$aria2 = new Aria2Client();
	$loopDelaySeconds = 3;

	logInfo('Worker started.');

	while (true) {
		try {
			Config::reloadOverrides();

			$jobs = fetchActiveJobs();
			if ($jobs === []) {
				sleepWithLog($loopDelaySeconds, 'No active downloads to process.');
				continue;
			}

			foreach ($jobs as $job) {
				handleJob($job, $aria2);
			}
		} catch (Throwable $exception) {
			logError('Worker error: ' . $exception->getMessage());
			sleep($loopDelaySeconds);
		}
	}
}

/**
 * Retrieves jobs that require monitoring.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchActiveJobs(): array
{
	$stmt = Db::run(
		"SELECT * FROM jobs WHERE status IN ('starting','downloading') AND aria2_gid IS NOT NULL ORDER BY updated_at ASC LIMIT 25"
	);

	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

	return $rows !== false ? $rows : [];
}

/**
 * Handles progress updates and lifecycle transitions for a job.
 *
 * @param array<string, mixed> $job
 */
function handleJob(array $job, Aria2Client $aria2): void
{
	try {
		$status = $aria2->tellStatus((string) $job['aria2_gid']);
	} catch (Throwable $exception) {
		if (!isStaleAria2Reference($exception)) {
			logError(sprintf('Failed to query aria2 for job %d: %s', $job['id'], $exception->getMessage()));

			return;
		}

		handleLostAria2Reference($job, $aria2, $exception);

		return;
	}

	$ariaState = (string) ($status['status'] ?? 'unknown');

	switch ($ariaState) {
		case 'complete':
			handleCompletion($job, $status);
			break;

		case 'error':
			handleFailure($job, $status, 'aria2 reported error state.');
			break;

		case 'removed':
			markCanceled((int) $job['id'], (int) $job['user_id'], 'Download removed from aria2.');
			break;

		case 'waiting':
		case 'paused':
		case 'active':
		default:
			updateProgress($job, $status);
			break;
	}
}

/**
 * Updates job progress metrics based on aria2 status.
 */
function updateProgress(array $job, array $status): void
{
	$completed = (int) ($status['completedLength'] ?? 0);
	$total = max((int) ($status['totalLength'] ?? 0), 0);

	$progress = $total > 0 ? (int) floor(($completed / $total) * 100) : 0;
	$progress = max(0, min(100, $progress));

	$speed = (int) ($status['downloadSpeed'] ?? 0);
	$eta = null;
	if ($speed > 0 && $total > $completed) {
		$eta = (int) ceil(($total - $completed) / $speed);
	}

	$files = $status['files'] ?? [];
	$firstPath = null;
	if (is_array($files) && isset($files[0]['path'])) {
		$firstPath = (string) $files[0]['path'];
	}

	$ariaState = (string) ($status['status'] ?? 'unknown');
	$newStatus = 'downloading';
	if ($ariaState === 'paused') {
		$newStatus = 'paused';
	}

	if ($newStatus === 'paused') {
		// Preserve paused state: clear speed/eta, keep progress and tmp_path for potential resume display.
		Db::run(
			"UPDATE jobs SET status = 'paused', progress = :progress, speed_bps = NULL, eta_seconds = NULL, tmp_path = :tmp_path, updated_at = :updated_at WHERE id = :id",
			[
				'progress' => $progress,
				'tmp_path' => $firstPath,
				'updated_at' => Clock::nowString(),
				'id' => $job['id'],
			]
		);
	} else {
		Db::run(
			"UPDATE jobs SET status = 'downloading', progress = :progress, speed_bps = :speed, eta_seconds = :eta, tmp_path = :tmp_path, updated_at = :updated_at WHERE id = :id",
			[
				'progress' => $progress,
				'speed' => $speed > 0 ? $speed : null,
				'eta' => $eta,
				'tmp_path' => $firstPath,
				'updated_at' => Clock::nowString(),
				'id' => $job['id'],
			]
		);
	}

	logInfo(sprintf('Job %d progress: %d%% (speed %d B/s).', $job['id'], $progress, $speed));
}

/**
 * Handles completion: moves the file into the library and triggers Jellyfin refresh.
 */
function handleCompletion(array $job, array $status): void
{
	try {
		$files = $status['files'] ?? [];
		if (!is_array($files) || !isset($files[0]['path'])) {
			throw new RuntimeException('aria2 did not report downloaded file path.');
		}

		$sourcePath = (string) $files[0]['path'];
		$finalPath = Jellyfin::moveDownloadToLibrary($job, $sourcePath);

		Db::run(
			"UPDATE jobs SET status = 'completed', progress = 100, speed_bps = NULL, eta_seconds = NULL, final_path = :final_path, tmp_path = NULL, updated_at = :updated_at WHERE id = :id",
			[
				'final_path' => $finalPath,
				'updated_at' => Clock::nowString(),
				'id' => $job['id'],
			]
		);

		Jellyfin::refreshLibrary();
		Audit::record((int) $job['user_id'], 'job.completed', 'job', (int) $job['id'], [
			'title' => $job['title'] ?? null,
			'final_path' => $finalPath,
		]);
		Events::notify((int) $job['user_id'], (int) $job['id'], 'job.completed', [
			'title' => $job['title'] ?? null,
			'final_path' => $finalPath,
		]);

		logInfo(sprintf('Job %d completed and moved to library: %s', $job['id'], $finalPath));
	} catch (Throwable $exception) {
		handleFailure($job, $status, 'Completion handling failed: ' . $exception->getMessage());
	}
}

/**
 * Marks a job as failed with detailed error output.
 */
function handleFailure(array $job, array $status, string $reason): void
{
	$message = $reason;
	if (isset($status['errorMessage'])) {
		$message .= ' ' . (string) $status['errorMessage'];
	}

	Db::run(
		"UPDATE jobs SET status = 'failed', error_text = :error, speed_bps = NULL, eta_seconds = NULL, updated_at = :updated_at WHERE id = :id",
		[
			'error' => $message,
			'updated_at' => Clock::nowString(),
			'id' => $job['id'],
		]
	);

	Audit::record((int) $job['user_id'], 'job.failed', 'job', (int) $job['id'], [
		'title' => $job['title'] ?? null,
		'error' => $message,
	]);
	Events::notify((int) $job['user_id'], (int) $job['id'], 'job.failed', [
		'title' => $job['title'] ?? null,
		'error' => $message,
	]);

	logError(sprintf('Job %d failed: %s', $job['id'], $message));
}

/**
 * Marks a job as canceled.
 */
function markCanceled(int $jobId, int $userId, string $reason): void
{
	Db::run(
		"UPDATE jobs SET status = 'canceled', error_text = :error, speed_bps = NULL, eta_seconds = NULL, aria2_gid = NULL, tmp_path = NULL, updated_at = :updated_at WHERE id = :id",
		[
			'error' => $reason,
			'updated_at' => Clock::nowString(),
			'id' => $jobId,
		]
	);

	$job = fetchJobRow($jobId);
	Audit::record($userId, 'job.canceled', 'job', $jobId, [
		'title' => $job['title'] ?? null,
		'reason' => $reason,
	]);
	Events::notify($userId, $jobId, 'job.canceled', ['reason' => $reason]);

	logInfo(sprintf('Job %d canceled: %s', $jobId, $reason));
}

function handleLostAria2Reference(array $job, Aria2Client $aria2, Throwable $exception): void
{
	$message = sprintf('Job %d lost aria2 reference (%s).', (int) $job['id'], $exception->getMessage());
	logInfo($message . ' Attempting recovery.');

	if (attemptRestartLostTransfer($job, $aria2)) {
		return;
	}

	removePartialFiles($job);
	markCanceled((int) $job['id'], (int) $job['user_id'], 'Canceled after failed aria2 restart; partial download removed.');
	logInfo(sprintf('Job %d canceled after recovery attempt failed.', (int) $job['id']));
}

function isStaleAria2Reference(Throwable $exception): bool
{
	$message = strtolower($exception->getMessage());

	if (str_contains($message, 'unexpected http status: 400')) {
		return true;
	}

	return str_contains($message, 'not found') || str_contains($message, 'invalid gid');
}

function attemptRestartLostTransfer(array $job, Aria2Client $aria2): bool
{
	$sourceUrl = isset($job['source_url']) ? trim((string) $job['source_url']) : '';
	if ($sourceUrl === '') {
		logInfo(sprintf('Job %d cannot be restarted: missing source URL.', (int) $job['id']));
		return false;
	}

	$downloadDir = (string) Config::get('paths.downloads');
	try {
		workerEnsureDirectory($downloadDir);
	} catch (Throwable $exception) {
		logError(sprintf('Job %d restart failed: unable to prepare download directory (%s).', (int) $job['id'], $exception->getMessage()));
		return false;
	}

	$options = [
		'dir' => $downloadDir,
	];

	$tmpPath = isset($job['tmp_path']) ? (string) $job['tmp_path'] : '';
	if ($tmpPath !== '') {
		$basename = basename($tmpPath);
		if ($basename !== '' && $basename !== '.' && $basename !== '..') {
			$options['out'] = $basename;
		}
	} else {
		$derived = deriveResumeFilename((string) ($job['title'] ?? ''), $sourceUrl);
		if ($derived !== '') {
			$options['out'] = $derived;
		}
	}

	try {
		$gid = $aria2->addUri([$sourceUrl], $options);
	} catch (Throwable $exception) {
		logError(sprintf('Job %d restart failed: %s', (int) $job['id'], $exception->getMessage()));
		return false;
	}

	$tmpPathToPersist = $tmpPath;
	if ($tmpPathToPersist === '' && isset($options['out'])) {
		$tmpPathToPersist = rtrim($downloadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $options['out'];
	}

	$progress = isset($job['progress']) ? (int) $job['progress'] : 0;

	Db::run(
		"UPDATE jobs SET status = 'downloading', progress = :progress, speed_bps = NULL, eta_seconds = NULL, aria2_gid = :gid, error_text = NULL, tmp_path = :tmp_path, updated_at = :updated_at WHERE id = :id",
		[
			'progress' => $progress,
			'gid' => $gid,
			'tmp_path' => $tmpPathToPersist !== '' ? $tmpPathToPersist : null,
			'updated_at' => Clock::nowString(),
			'id' => $job['id'],
		]
	);

	Audit::record((int) $job['user_id'], 'job.requeued', 'job', (int) $job['id'], [
		'title' => $job['title'] ?? null,
		'aria2_gid' => $gid,
		'source_url' => $sourceUrl,
	]);
	Events::notify((int) $job['user_id'], (int) $job['id'], 'job.requeued', [
		'title' => $job['title'] ?? null,
		'aria2_gid' => $gid,
	]);

	logInfo(sprintf('Job %d restarted with new aria2 gid %s.', (int) $job['id'], $gid));

	return true;
}

function deriveResumeFilename(string $title, string $sourceUrl): string
{
	$path = parse_url($sourceUrl, PHP_URL_PATH);
	if (is_string($path)) {
		$basename = basename($path);
		if ($basename !== '' && $basename !== '.' && $basename !== '..') {
			return $basename;
		}
	}

	$slug = preg_replace('/[^A-Za-z0-9._-]+/', '_', $title) ?? '';
	$slug = trim($slug, '_');
	if ($slug === '') {
		$slug = 'download';
	}

	$extension = '';
	if (preg_match('~\.(mkv|mp4|avi|mov|webm|mpg|mpeg)$~i', $sourceUrl, $matches)) {
		$extension = '.' . strtolower($matches[1]);
	}

	if ($extension !== '' && !str_ends_with(strtolower($slug), strtolower($extension))) {
		$slug .= $extension;
	}

	return $slug;
}

function removePartialFiles(array $job): void
{
	$tmpPath = isset($job['tmp_path']) ? (string) $job['tmp_path'] : '';
	if ($tmpPath === '') {
		return;
	}

	$paths = [$tmpPath, $tmpPath . '.aria2'];
	foreach ($paths as $path) {
		if ($path === '' || !file_exists($path) || is_dir($path)) {
			continue;
		}

		if (@unlink($path)) {
			logInfo(sprintf('Removed partial download file %s for job %d.', $path, (int) $job['id']));
		}
	}
}

function workerEnsureDirectory(string $path): void
{
	if ($path === '') {
		return;
	}

	if (is_dir($path)) {
		return;
	}

	if (!mkdir($path, 0775, true) && !is_dir($path)) {
		throw new RuntimeException('Unable to create directory: ' . $path);
	}
}

/**
 * Retrieves a single job row for helper routines.
 *
 * @return array<string, mixed>|null
 */
function fetchJobRow(int $jobId): ?array
{
	$stmt = Db::run('SELECT * FROM jobs WHERE id = :id LIMIT 1', ['id' => $jobId]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

	return $row !== false ? $row : null;
}
