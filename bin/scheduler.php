#!/usr/bin/env php
<?php
declare(strict_types=1);

use App\Download\Aria2Client;
use App\Infra\Audit;
use App\Infra\Config;
use App\Infra\Db;
use App\Infra\ProviderSecrets;
use App\Providers\VideoProvider;
use App\Providers\WebshareProvider;
use App\Support\Clock;

/**
 * Scheduler loop: selects queued jobs and enqueues them to aria2 while
 * respecting concurrency and free-space thresholds.
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
	runSchedulerLoop($root);
}

/**
 * Boots a basic PSR-4 autoloader fallback if Composer is unavailable.
 */

/**
 * Runs the scheduler loop until interrupted (skipped during tests).
 */
function runSchedulerLoop(string $root): void
{
	Config::boot($root . '/config');

	$aria2 = new Aria2Client();
	$loopDelaySeconds = 3;

	logInfo('Scheduler started.');

	while (true) {
		try {
			Config::reloadOverrides();

			if (!hasFreeSpace()) {
				sleepWithLog($loopDelaySeconds, 'Awaiting free disk space.');
				continue;
			}

			if (!hasCapacity()) {
				sleepWithLog($loopDelaySeconds, 'Concurrency limit reached.');
				continue;
			}

			$job = claimNextJob();
			if ($job === null) {
				sleepWithLog($loopDelaySeconds, 'No queued jobs found.');
				continue;
			}

			processJob($job, $aria2);
		} catch (Throwable $exception) {
			logError('Scheduler error: ' . $exception->getMessage());
			sleep($loopDelaySeconds);
		}
	}
}

/**
 * Attempts to claim the next queued job by setting its status to `starting`.
 *
 * @return array<string, mixed>|null
 */
function claimNextJob(): ?array
{
	return Db::transaction(static function () {
		$stmt = Db::run(
			"SELECT * FROM jobs WHERE status = 'queued' ORDER BY priority ASC, position ASC, created_at ASC LIMIT 1"
		);

		$job = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($job === false) {
			return null;
		}

		Db::run(
			"UPDATE jobs SET status = 'starting', updated_at = :updated_at WHERE id = :id",
			[
				'id' => $job['id'],
				'updated_at' => Clock::nowString(),
			]
		);

		Audit::record((int) $job['user_id'], 'job.starting', 'job', (int) $job['id'], [
			'title' => $job['title'] ?? null,
			'provider_id' => (int) $job['provider_id'],
		]);

		$job['status'] = 'starting';

		return $job;
	});
}

/**
 * Processes the job: resolve provider URL and enqueue with aria2.
 *
 * @param array<string, mixed> $job
 */
function processJob(array $job, Aria2Client $aria2): void
{
	$providerRow = fetchProvider((int) $job['provider_id']);
	if ($providerRow === null || (int) $providerRow['enabled'] !== 1) {
		failJob((int) $job['id'], 'Provider disabled or missing.', $job);

		return;
	}

	$provider = buildProvider($providerRow);

	try {
		$resolved = $provider->resolveDownloadUrl((string) $job['external_id']);
		$uris = is_array($resolved) ? array_values($resolved) : [(string) $resolved];
		$uris = array_filter(array_map('trim', $uris));

		if ($uris === []) {
			throw new RuntimeException('Provider did not return any download URIs.');
		}

		$downloadDir = (string) Config::get('paths.downloads');
		ensureDirectory($downloadDir);

		$options = [
			'dir' => $downloadDir,
		];

		$gid = $aria2->addUri($uris, $options);

		Db::run(
			"UPDATE jobs SET status = 'downloading', aria2_gid = :gid, source_url = :source_url, updated_at = :updated_at WHERE id = :id",
			[
				'gid' => $gid,
				'source_url' => $uris[0],
				'updated_at' => Clock::nowString(),
				'id' => $job['id'],
			]
		);

		Audit::record((int) $job['user_id'], 'job.downloading', 'job', (int) $job['id'], [
			'title' => $job['title'] ?? null,
			'aria2_gid' => $gid,
			'source_url' => $uris[0] ?? null,
		]);

		logInfo(sprintf('Job %d enqueued with aria2 gid %s.', $job['id'], $gid));
	} catch (Throwable $exception) {
		failJob((int) $job['id'], 'Failed to enqueue job: ' . $exception->getMessage(), $job);
	}
}

/**
 * Retrieves the provider row from the database.
 *
 * @return array<string, mixed>|null
 */
function fetchProvider(int $providerId): ?array
{
	$stmt = Db::run('SELECT * FROM providers WHERE id = :id LIMIT 1', ['id' => $providerId]);
	$provider = $stmt->fetch(PDO::FETCH_ASSOC);

	return $provider !== false ? $provider : null;
}

/**
 * Builds the provider implementation from a database row.
 */
function buildProvider(array $providerRow): VideoProvider
{
	$key = (string) $providerRow['key'];
	$config = ProviderSecrets::decrypt($providerRow);

	return match ($key) {
		'webshare' => new WebshareProvider($config),
		'kraska' => new App\Providers\KraSkProvider($config),
		default => throw new RuntimeException('Unsupported provider: ' . $key),
	};
}

/**
 * Marks a job as failed with the provided error message.
 */
function failJob(int $jobId, string $message, ?array $job = null): void
{
	Db::run(
		"UPDATE jobs SET status = 'failed', error_text = :error, updated_at = :updated_at WHERE id = :id",
		[
			'error' => $message,
			'updated_at' => Clock::nowString(),
			'id' => $jobId,
		]
	);

	if ($job === null) {
		$job = fetchJobForAudit($jobId);
	}

	if (is_array($job)) {
		Audit::record((int) $job['user_id'], 'job.failed', 'job', $jobId, [
			'title' => $job['title'] ?? null,
			'error' => $message,
		]);
	}

	logError(sprintf('Job %d failed: %s', $jobId, $message));
}

/**
 * Fetches minimal job data for audit logging when not already available.
 *
 * @return array<string, mixed>|null
 */
function fetchJobForAudit(int $jobId): ?array
{
	$stmt = Db::run('SELECT id, user_id, title FROM jobs WHERE id = :id LIMIT 1', ['id' => $jobId]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

	return $row !== false ? $row : null;
}

/**
 * Ensures the download directory exists.
 */
function ensureDirectory(string $path): void
{
	if (is_dir($path)) {
		return;
	}

	if (!mkdir($path, 0775, true) && !is_dir($path)) {
		throw new RuntimeException('Unable to create directory: ' . $path);
	}
}

/**
 * Checks active download capacity against configuration limit.
 */
function hasCapacity(): bool
{
	$limit = (int) Config::get('app.max_active_downloads');
	if ($limit <= 0) {
		return true;
	}

	$stmt = Db::run(
		"SELECT COUNT(*) FROM jobs WHERE status IN ('starting','downloading')"
	);

	$count = (int) $stmt->fetchColumn();

	return $count < $limit;
}

/**
 * Verifies that both download and library paths meet the minimum free-space requirement.
 */
function hasFreeSpace(): bool
{
	$minGb = (float) Config::get('app.min_free_space_gb');
	if ($minGb <= 0) {
		return true;
	}

	$paths = [
		(string) Config::get('paths.downloads'),
		(string) Config::get('paths.library'),
	];

	foreach ($paths as $path) {
		ensureDirectory($path);
		$freeBytes = disk_free_space($path);
		if ($freeBytes === false) {
			continue;
		}

		$freeGb = $freeBytes / (1024 ** 3);
		if ($freeGb < $minGb) {
			logInfo(sprintf('Insufficient free space on %s: %.2f GB available.', $path, $freeGb));

			return false;
		}
	}

	return true;
}
