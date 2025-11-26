#!/usr/bin/env php
<?php
declare(strict_types=1);

use App\Download\Aria2Client;
use App\Infra\Audit;
use App\Infra\Config;
use App\Infra\Db;
use App\Infra\ProviderBackoff;
use App\Infra\ProviderPause;
use App\Infra\ProviderSecrets;
use App\Providers\VideoProvider;
use App\Providers\WebshareProvider;
use App\Providers\RateLimitDeferredException;
use App\Providers\KraSkApiException;
use App\Providers\KraSkProvider;
use App\Providers\KraSk2Provider;
use App\Providers\ProviderBackoffException;
use App\Support\Clock;
use App\Support\LogRotation;

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

	// Rotate logs on startup
	$logsDir = $root . '/storage/logs';
	LogRotation::rotateAll($logsDir, 5, 10485760); // Keep 5 rotations, max 10MB per file

	$aria2 = new Aria2Client();
	$loopDelaySeconds = 3;
	$providerThrottleUntil = [];
	$logRotationIntervalSeconds = 300; // Check every 5 minutes
	$lastLogRotation = time();

	logInfo('Scheduler started.');

	while (true) {
		try {
			Config::reloadOverrides();
			synchronizeProviderBackoff($providerThrottleUntil);

			// Requeue orphan 'starting' jobs that never obtained an aria2_gid (e.g. provider init exception earlier)
			$requeued = cleanupOrphanStartingJobs();
			if ($requeued > 0) {
				logInfo(sprintf('Requeued %d orphan starting job(s) without aria2_gid.', $requeued));
			}

			// Periodically rotate logs during runtime
			$now = time();
			if (($now - $lastLogRotation) >= $logRotationIntervalSeconds) {
				LogRotation::rotateAll($logsDir, 5, 10485760);
				$lastLogRotation = $now;
			}

			if (!hasFreeSpace()) {
				sleepWithLog($loopDelaySeconds, 'Awaiting free disk space.');
				continue;
			}

			if (!hasCapacity()) {
				sleepWithLog($loopDelaySeconds, 'Concurrency limit reached.');
				continue;
			}

			$skipProviderIds = [];
			foreach ($providerThrottleUntil as $providerId => $until) {
				if ($until > $now) {
					$skipProviderIds[] = (int) $providerId;
				} else {
					unset($providerThrottleUntil[$providerId]);
				}
			}

			$pausedProviderIds = ProviderPause::providerIds();
			if ($pausedProviderIds !== []) {
				$skipProviderIds = array_merge($skipProviderIds, $pausedProviderIds);
			}
			if ($skipProviderIds !== []) {
				$skipProviderIds = array_values(array_unique($skipProviderIds));
			}

			$job = claimNextJob($skipProviderIds);
			if ($job === null) {
				if ($pausedProviderIds !== []) {
					sleepWithLog($loopDelaySeconds, 'No queued jobs found (some providers paused).');
				} else {
					sleepWithLog($loopDelaySeconds, 'No queued jobs found.');
				}
				continue;
			}

			$providerId = (int) $job['provider_id'];
			$providerRow = fetchProvider($providerId);
			if ($providerRow === null || (int) $providerRow['enabled'] !== 1) {
				failJob((int) $job['id'], 'Provider disabled or missing.', $job, [
					'provider_id' => $providerId,
					'job_external_id' => $job['external_id'] ?? null,
				]);
				continue;
			}

			try {
				processJob($job, $providerRow, $aria2);
			} catch (RateLimitDeferredException $exception) {
				returnJobToQueue((int) $job['id']);
				$providerThrottleUntil[$providerId] = time() + $exception->getRetryAfterSeconds();
				$providerName = isset($providerRow['name']) && $providerRow['name'] !== ''
					? (string) $providerRow['name']
					: sprintf('Provider #%d', $providerId);
				logInfo(sprintf(
					'%s rate limited; will retry job %d in approximately %d seconds.',
					$providerName,
					(int) $job['id'],
					$exception->getRetryAfterSeconds()
				));
				continue;
			} catch (ProviderBackoffException $exception) {
				returnJobToQueue((int) $job['id']);
				$retrySeconds = $exception->getRetryAfterSeconds();
				$retryAt = time() + $retrySeconds;
				$providerThrottleUntil[$providerId] = $retryAt;
				$context = $exception->getContext();
				$providerLabel = isset($providerRow['name']) && $providerRow['name'] !== ''
					? (string) $providerRow['name']
					: ucfirst($exception->getProviderKey());
				ProviderBackoff::set(
					$exception->getProviderKey(),
					$retryAt,
					[
						'provider_label' => $providerLabel,
						'reason' => $context['reason'] ?? 'temporary_failure',
						'message' => $exception->getMessage(),
						'retry_after_seconds' => $retrySeconds,
						'job' => [
							'id' => (int) $job['id'],
							'title' => $job['title'] ?? null,
							'external_id' => $job['external_id'] ?? null,
						],
						'error' => [
							'status_code' => $context['status_code'] ?? null,
							'endpoint' => $context['endpoint'] ?? null,
							'response_preview' => $context['response_preview'] ?? null,
						],
					]
				);
				Audit::record((int) $job['user_id'], 'job.deferred.backoff', 'job', (int) $job['id'], [
					'provider_key' => $exception->getProviderKey(),
					'retry_after_seconds' => $retrySeconds,
					'retry_at' => gmdate('c', $retryAt),
					'message' => $exception->getMessage(),
				]);
				logInfo(sprintf(
					'%s temporarily unavailable (%s). Will retry job %d in approximately %d seconds.',
					$providerLabel,
					$exception->getMessage(),
					(int) $job['id'],
					$retrySeconds
				));
				continue;
			}
		} catch (Throwable $exception) {
			logError('Scheduler error: ' . $exception->getMessage());
			sleep($loopDelaySeconds);
		}
	}
}

/**
 * Attempts to claim the next queued job by setting its status to `starting`.
 *
 * @param array<int> $skipProviderIds
 * @return array<string, mixed>|null
 */
function claimNextJob(array $skipProviderIds = []): ?array
{
	return Db::transaction(static function () use ($skipProviderIds) {
		$params = [];
		$sql = "SELECT * FROM jobs WHERE status = 'queued'";
		if ($skipProviderIds !== []) {
			$placeholders = implode(',', array_fill(0, count($skipProviderIds), '?'));
			$sql .= " AND provider_id NOT IN ($placeholders)";
			$params = array_map('intval', $skipProviderIds);
		}
		$sql .= " ORDER BY priority ASC, position ASC, created_at ASC LIMIT 1";

		$stmt = Db::run($sql, $params);

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
 * @param array<string, mixed> $providerRow
 */

function processJob(array $job, array $providerRow, Aria2Client $aria2): void
{
	$providerKey = isset($providerRow['key']) ? (string) $providerRow['key'] : '';
	try {
		$provider = buildProvider($providerRow);
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

		// Attempt to derive a human friendly target filename so aria2 stores the file under a Jellyfin friendly name.
		$firstUri = $uris[0];
		$options['out'] = deriveOutputFilename((string) ($job['title'] ?? 'download'), $firstUri);

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

		if ($providerKey !== '') {
			ProviderBackoff::clear($providerKey);
		}

		logInfo(sprintf('Job %d enqueued with aria2 gid %s.', $job['id'], $gid));
	} catch (RateLimitDeferredException $exception) {
		throw $exception;
	} catch (Throwable $exception) {
		if ($providerKey === 'kraska' && $exception instanceof KraSkApiException && isKraskaInvalidIdent($exception)) {
			$backoffSeconds = getKraskaBackoffSeconds();
			$responsePreview = $exception->getResponseBody();
			throw new ProviderBackoffException(
				'kraska',
				(int) $job['provider_id'],
				$backoffSeconds,
				'Kra.sk API returned invalid ident (HTTP 400).',
				[
					'reason' => 'invalid_ident',
					'status_code' => $exception->getStatusCode(),
					'endpoint' => $exception->getEndpoint(),
					'response_preview' => is_string($responsePreview) ? substr($responsePreview, 0, 500) : null,
					'job_external_id' => $job['external_id'] ?? null,
					'job_title' => $job['title'] ?? null,
				],
				$exception
			);
		}

		if ($providerKey === 'kraska' && isKraskaObjectNotFound($exception)) {
			$backoffSeconds = getKraskaBackoffSeconds();
			$context = kraskaExceptionContext($exception);
			$context['reason'] = 'object_not_found';
			$context['error_code'] = 1210;
			$context['job_external_id'] = $job['external_id'] ?? null;
			$context['job_title'] = $job['title'] ?? null;

			throw new ProviderBackoffException(
				'kraska',
				(int) $job['provider_id'],
				$backoffSeconds,
				'Kra.sk API reported object not found (code 1210).',
				$context,
				$exception
			);
		}

		$details = [
			'exception_class' => $exception::class,
			'job_external_id' => $job['external_id'] ?? null,
			'provider_id' => (int) ($job['provider_id'] ?? 0),
			'provider_key' => $providerRow['key'] ?? null,
		];

		if (isset($provider) && $provider instanceof KraSkProvider) {
			$details['provider'] = 'kraska';

			if ($exception instanceof KraSkApiException) {
				$details['request'] = [
					'url' => $exception->getUrl(),
					'endpoint' => $exception->getEndpoint(),
					'status_code' => $exception->getStatusCode(),
					'payload' => $exception->getPayload(),
				];

				$responsePreview = $exception->getResponseBody();
				if (is_string($responsePreview) && $responsePreview !== '') {
					$details['response_preview'] = substr($responsePreview, 0, 500);
				}
			}
		}

		failJob((int) $job['id'], 'Failed to enqueue job: ' . $exception->getMessage(), $job, $details);
	}
}

/**
 * Sanitize a prospective filename by removing or replacing characters that commonly
 * cause issues across filesystems and media servers. Keeps letters, numbers, spaces,
 * dots, dashes, underscores, parentheses, apostrophes.
 */
function sanitizeFilename(string $name): string
{
	// Normalize unicode whitespace to space
	$name = preg_replace('/\s+/u', ' ', $name) ?? $name;

	// Explicit map for common Central European diacritics so we preserve base letters.
	$map = [
		// Czech & Slovak
		'á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z',
		'Á'=>'A','Č'=>'C','Ď'=>'D','É'=>'E','Ě'=>'E','Í'=>'I','Ň'=>'N','Ó'=>'O','Ř'=>'R','Š'=>'S','Ť'=>'T','Ú'=>'U','Ů'=>'U','Ý'=>'Y','Ž'=>'Z',
		'ľ'=>'l','ĺ'=>'l','ô'=>'o','ŕ'=>'r','Ĺ'=>'L','Ľ'=>'L','Ŕ'=>'R','Ô'=>'O','Ä'=>'A','ä'=>'a',
		// Polish
		'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ś'=>'s','ź'=>'z','ż'=>'z',
		'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ś'=>'S','Ź'=>'Z','Ż'=>'Z',
		// German
		'ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue',
		// French
		'à'=>'a','â'=>'a','æ'=>'ae','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','ï'=>'i','î'=>'i','ô'=>'o','œ'=>'oe','ù'=>'u','û'=>'u','ü'=>'u','ÿ'=>'y',
		'À'=>'A','Â'=>'A','Æ'=>'Ae','Ç'=>'C','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Ï'=>'I','Î'=>'I','Ô'=>'O','Œ'=>'Oe','Ù'=>'U','Û'=>'U','Ü'=>'U','Ÿ'=>'Y',
		// Spanish & Portuguese
		'ñ'=>'n','Ñ'=>'N','á'=>'a','Á'=>'A','é'=>'e','É'=>'E','í'=>'i','Í'=>'I','ó'=>'o','Ó'=>'O','ú'=>'u','Ú'=>'U','ü'=>'u','Ü'=>'U','ã'=>'a','Ã'=>'A','õ'=>'o','Õ'=>'O','ê'=>'e','Ê'=>'E','ô'=>'o','Ô'=>'O','à'=>'a','À'=>'A','ç'=>'c','Ç'=>'C',
		// Scandinavian (avoid overwriting German ae/oe forms; keep simple forms where not conflicting)
		'å'=>'a','Å'=>'A','ø'=>'o','Ø'=>'O','æ'=>'ae','Æ'=>'Ae',
		// Hungarian (retain German-style oe/ue for shared characters; map long double-accent letters)
		'ő'=>'o','ű'=>'u','Ő'=>'O','Ű'=>'U','í'=>'i','Í'=>'I','é'=>'e','É'=>'E','á'=>'a','Á'=>'A','ó'=>'o','Ó'=>'O','ú'=>'u','Ú'=>'U',
	];
	// Perform replacements (note multi-letter expansions like ae/oe/ss occur prior to unsafe char stripping)
	$name = strtr($name, $map);
	// Remove BBCode remnants or stray brackets leftover
	$name = preg_replace('/\[[^\]]+\]/', '', $name) ?? $name;
	// Strip any path separators
	$name = str_replace(['/', '\\'], ' ', $name);
	// Allow safe chars only (keeps comma); plus signs will be dropped as unsafe
	$name = preg_replace("/[^A-Za-z0-9 ._()'\-,]/u", '', $name) ?? $name;
	// Collapse multiple spaces
	$name = trim(preg_replace('/ {2,}/', ' ', $name) ?? $name);
	return $name;
}

/**
 * Derive final output filename with extension from URI.
 */
function deriveOutputFilename(string $title, string $uri): string
{
	// Jellyfin-friendly matching: if the title contains a trailing language code list just before the year,
	// strip it. Example: "Movie Name - EN, CZ, ENG+tit (2024)" -> "Movie Name (2024)".
	// We only remove it if EVERY comma-separated token matches the language pattern to avoid false positives.
	$cleanTitle = $title;
	if (preg_match('/^(.*?)\s-\s([^()]+?)\s*(\(\d{4}\))$/u', trim($title), $m)) {
		$prefix = trim($m[1]);
		$list = $m[2];
		$yearPart = $m[3];
		$tokens = array_map('trim', explode(',', $list));
		$allLang = true;
		foreach ($tokens as $t) {
			if ($t === '') { $allLang = false; break; }
			if (!preg_match('/^[A-Z]{2,5}(?:\+(?:tit|sub))?$/', $t)) { $allLang = false; break; }
		}
		if ($allLang) {
			$hasSuffix = false;
			foreach ($tokens as $t) { if (str_contains($t, '+')) { $hasSuffix = true; break; } }
			// Strip only if 3+ language tokens OR any token carries a suffix (+tit/+sub)
			if (count($tokens) >= 3 || $hasSuffix) {
				$cleanTitle = $prefix . ' ' . $yearPart; // Ensure single space before year
			}
		}
	}

	$sanitized = sanitizeFilename($cleanTitle);
	if ($sanitized === '') {
		$sanitized = 'download';
	}
	$extension = '.mkv';
	$pathPart = parse_url($uri, PHP_URL_PATH) ?? '';
	if (is_string($pathPart) && preg_match('~\.(mkv|mp4|avi|mov|webm|mpg|mpeg)$~i', $pathPart, $m)) {
		$extension = '.' . strtolower($m[1]);
	} elseif (preg_match('~\.(mkv|mp4|avi|mov|webm|mpg|mpeg)(?:\?|$)~i', $uri, $m)) {
		$extension = '.' . strtolower($m[1]);
	}
	return $sanitized . $extension;
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
	static $cache = [];

	$providerId = isset($providerRow['id']) ? (int) $providerRow['id'] : 0;
	$key = (string) $providerRow['key'];
	$configFingerprint = sha1($key . '|' . ($providerRow['config_json'] ?? '') . '|' . ($providerRow['updated_at'] ?? ''));

	if (isset($cache[$providerId]) && $cache[$providerId]['fingerprint'] === $configFingerprint) {
		return $cache[$providerId]['provider'];
	}

	$config = ProviderSecrets::decrypt($providerRow);

	// Inject debug setting from application config for kraska provider
	if ($key === 'kraska') {
		$config['debug'] = Config::get('providers.kraska_debug_enabled');
	}

	$provider = match ($key) {
		'webshare' => new WebshareProvider($config),
		'kraska' => new KraSkProvider($config),
		'krask2' => new KraSk2Provider($config),
		default => throw new RuntimeException('Unsupported provider: ' . $key),
	};

	$cache[$providerId] = [
		'fingerprint' => $configFingerprint,
		'provider' => $provider,
	];

	return $provider;
}

/**
 * Marks a job as failed with the provided error message.
 */
function failJob(int $jobId, string $message, ?array $job = null, array $details = []): void
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
		$payload = [
			'title' => $job['title'] ?? null,
			'error' => $message,
		];

		if ($details !== []) {
			$payload['details'] = $details;
		}

		Audit::record((int) $job['user_id'], 'job.failed', 'job', $jobId, $payload);
	}

	logError(sprintf('Job %d failed: %s', $jobId, $message));
}

/**
 * Returns a claimed job back to the queued state without marking it as failed.
 */
function returnJobToQueue(int $jobId): void
{
	Db::run(
		"UPDATE jobs SET status = 'queued', aria2_gid = NULL, updated_at = :updated_at WHERE id = :id",
		[
			'updated_at' => Clock::nowString(),
			'id' => $jobId,
		]
	);
}

/**
 * Aligns the in-memory throttle state with persisted provider backoff windows.
 *
 * @param array<int, int> $providerThrottleUntil
 */
function synchronizeProviderBackoff(array &$providerThrottleUntil): void
{
	$active = ProviderBackoff::active();
	if ($active === []) {
		return;
	}

	$now = time();
	foreach ($active as $entry) {
		$providerKey = $entry['provider'] ?? null;
		$retryAt = isset($entry['retry_at_unix']) ? (int) $entry['retry_at_unix'] : null;
		if (!is_string($providerKey) || $providerKey === '' || $retryAt === null) {
			continue;
		}
		if ($retryAt <= $now) {
			ProviderBackoff::clear($providerKey);
			continue;
		}

		$providerId = resolveProviderIdByKey($providerKey);
		if ($providerId === null) {
			continue;
		}

		if (!isset($providerThrottleUntil[$providerId]) || $providerThrottleUntil[$providerId] < $retryAt) {
			$providerThrottleUntil[$providerId] = $retryAt;
		}
	}
}

function resolveProviderIdByKey(string $providerKey): ?int
{
	static $cache = [];

	if (array_key_exists($providerKey, $cache)) {
		return $cache[$providerKey];
	}

	try {
		$statement = Db::run('SELECT id FROM providers WHERE key = :key LIMIT 1', ['key' => $providerKey]);
		$value = $statement->fetchColumn();
		$cache[$providerKey] = is_numeric($value) ? (int) $value : null;
	} catch (Throwable) {
		$cache[$providerKey] = null;
	}

	return $cache[$providerKey];
}

function getKraskaBackoffSeconds(): int
{
	try {
		$value = (int) Config::get('providers.kraska_error_backoff_seconds');
	} catch (Throwable) {
		$value = 300;
	}

	if ($value <= 0) {
		$value = 300;
	}

	return max(60, $value);
}

function isKraskaInvalidIdent(KraSkApiException $exception): bool
{
	if ($exception->getStatusCode() !== 400) {
		return false;
	}

	$body = $exception->getResponseBody();
	if (is_string($body) && $body !== '') {
		if (stripos($body, 'invalid ident') !== false) {
			return true;
		}
		$decoded = json_decode($body, true);
		if (is_array($decoded)) {
			$errorCode = $decoded['error'] ?? $decoded['code'] ?? null;
			if (is_numeric($errorCode) && (int) $errorCode === 1207) {
				return true;
			}
			$message = $decoded['msg'] ?? $decoded['message'] ?? null;
			if (is_string($message) && stripos($message, 'invalid ident') !== false) {
				return true;
			}
		}
	}

	return false;
}

function kraskaStringIndicatesObjectNotFound(string $value): bool
{
	$normalized = strtolower($value);

	if (str_contains($normalized, 'object not found')) {
		return true;
	}

	return preg_match('/\b1210\b/', $normalized) === 1;
}

function isKraskaObjectNotFound(Throwable $exception): bool
{
	for ($cursor = $exception; $cursor !== null; $cursor = $cursor->getPrevious()) {
		$message = $cursor->getMessage();
		if ($message !== '' && kraskaStringIndicatesObjectNotFound($message)) {
			return true;
		}

		if ($cursor instanceof KraSkApiException) {
			$body = $cursor->getResponseBody();
			if (is_string($body) && $body !== '') {
				if (kraskaStringIndicatesObjectNotFound($body)) {
					return true;
				}

				$decoded = json_decode($body, true);
				if (is_array($decoded)) {
					$errorCode = $decoded['error'] ?? $decoded['code'] ?? null;
					if (is_numeric($errorCode) && (int) $errorCode === 1210) {
						return true;
					}
					$messageField = $decoded['msg'] ?? $decoded['message'] ?? null;
					if (is_string($messageField) && kraskaStringIndicatesObjectNotFound($messageField)) {
						return true;
					}
				}
			}
		}
	}

	return false;
}

/**
 * @return array{status_code: int|null, endpoint: string|null, response_preview: string|null}
 */
function kraskaExceptionContext(Throwable $exception): array
{
	$statusCode = null;
	$endpoint = null;
	$responsePreview = null;

	for ($cursor = $exception; $cursor !== null; $cursor = $cursor->getPrevious()) {
		if ($cursor instanceof KraSkApiException) {
			$statusCode = $cursor->getStatusCode();
			$endpoint = $cursor->getEndpoint();
			$body = $cursor->getResponseBody();
			if (is_string($body) && $body !== '') {
				$responsePreview = substr($body, 0, 500);
			}
			break;
		}
	}

	if ($responsePreview === null) {
		$message = $exception->getMessage();
		if ($message !== '') {
			$responsePreview = substr($message, 0, 500);
		}
	}

	return [
		'status_code' => $statusCode,
		'endpoint' => $endpoint,
		'response_preview' => $responsePreview,
	];
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
 * Requeues jobs stuck in 'starting' without an aria2_gid after a grace period.
 * This prevents phantom active slots when a provider initialization error occurred
 * before we transitioned the job to 'downloading'.
 */
function cleanupOrphanStartingJobs(int $ageSeconds = 60): int
{
	$thresholdSql = "SELECT id FROM jobs WHERE status = 'starting' AND (aria2_gid IS NULL OR aria2_gid = '') AND updated_at < datetime('now', :offset)";
	$offset = sprintf('-%d seconds', max(5, $ageSeconds));
	$stmt = Db::run($thresholdSql, ['offset' => $offset]);
	$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
	if ($ids === false || $ids === []) {
		return 0;
	}

	$now = Clock::nowString();
	foreach ($ids as $id) {
		Db::run(
			"UPDATE jobs SET status='queued', updated_at=:updated_at, aria2_gid=NULL WHERE id = :id",
			[
				'updated_at' => $now,
				'id' => (int) $id,
			]
		);
		// Minimal audit trail (job might later fail again if provider is still misconfigured)
		Audit::record(0, 'job.requeued.orphan', 'job', (int) $id, []);
	}

	return count($ids);
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
