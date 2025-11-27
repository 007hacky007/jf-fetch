<?php

declare(strict_types=1);

use App\Infra\Audit;
use App\Infra\Auth;
use App\Infra\Config;
use App\Infra\Http;
use App\Infra\Settings;
use RuntimeException;
use Throwable;

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
	Auth::boot();
	Auth::requireRole('admin');
} catch (RuntimeException $exception) {
	Http::error(403, $exception->getMessage());

	return;
}

if ($method === 'GET') {
	Config::reloadOverrides();
	Http::json(200, ['data' => buildSettingsResponse()]);

	return;
}

if ($method !== 'PUT') {
	Http::error(405, 'Method not allowed');

	return;
}

try {
	$payload = Http::readJsonBody();
} catch (RuntimeException $exception) {
	Http::error(400, $exception->getMessage());

	return;
}

[$flattened, $errors] = validateSettingsPayload($payload);
if ($errors !== []) {
	Http::error(422, 'Validation failed', ['errors' => $errors]);

	return;
}

try {
	Settings::setMany($flattened);
	Config::reloadOverrides();

	$user = Auth::user();
	if (is_array($user) && isset($user['id'])) {
		Audit::record((int) $user['id'], 'settings.updated', 'settings', null, ['keys' => array_keys($flattened)]);
	}
} catch (Throwable $exception) {
	Http::error(500, 'Failed to persist settings: ' . $exception->getMessage());

	return;
}

Http::json(200, ['data' => buildSettingsResponse()]);

/**
 * Builds the settings response structure returned to the UI.
 *
 * @return array<string, array<string, mixed>>
 */
function buildSettingsResponse(): array
{
	return [
		'app' => [
			'base_url' => (string) Config::get('app.base_url'),
			'max_active_downloads' => (int) Config::get('app.max_active_downloads'),
			'min_free_space_gb' => (float) Config::get('app.min_free_space_gb'),
			'default_search_limit' => (int) Config::get('app.default_search_limit'),
		],
		'aria2' => [
			'max_speed_mb_s' => normalizeAria2MaxSpeed(),
		],
		'paths' => [
			'downloads' => (string) Config::get('paths.downloads'),
			'library' => (string) Config::get('paths.library'),
		],
		'jellyfin' => [
			'url' => (string) Config::get('jellyfin.url'),
			'api_key' => (string) Config::get('jellyfin.api_key'),
			'library_id' => (string) Config::get('jellyfin.library_id'),
		],
		'providers' => [
			'kraska_menu_cache_ttl_seconds' => (int) Config::get('providers.kraska_menu_cache_ttl_seconds'),
			'kraska_debug_enabled' => (bool) Config::get('providers.kraska_debug_enabled'),
			'kraska_error_backoff_seconds' => (int) Config::get('providers.kraska_error_backoff_seconds'),
			'krask2_download_spacing_seconds' => (int) Config::get('providers.krask2_download_spacing_seconds'),
		],
	];
}

function normalizeAria2MaxSpeed(): float
{
	if (!Config::has('aria2.max_speed_mb_s')) {
		return 0.0;
	}

	$value = Config::get('aria2.max_speed_mb_s');
	if ($value === null) {
		return 0.0;
	}

	if (is_string($value)) {
		$value = trim($value);
		if ($value === '') {
			return 0.0;
		}
	}

	if (!is_numeric($value)) {
		return 0.0;
	}

	$float = (float) $value;
	if ($float < 0) {
		return 0.0;
	}

	return round($float, 2);
}

/**
 * Validates and normalises the incoming settings payload.
 *
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function validateSettingsPayload(array $payload): array
{
	$definitions = [
		'app.base_url' => ['section' => 'app', 'field' => 'base_url', 'type' => 'string', 'required' => true],
		'app.max_active_downloads' => ['section' => 'app', 'field' => 'max_active_downloads', 'type' => 'int', 'min' => 0],
		'app.min_free_space_gb' => ['section' => 'app', 'field' => 'min_free_space_gb', 'type' => 'float', 'min' => 0],
		'app.default_search_limit' => ['section' => 'app', 'field' => 'default_search_limit', 'type' => 'int', 'min' => 1, 'max' => 100],
		'aria2.max_speed_mb_s' => ['section' => 'aria2', 'field' => 'max_speed_mb_s', 'type' => 'float', 'min' => 0],
		'providers.kraska_menu_cache_ttl_seconds' => ['section' => 'providers', 'field' => 'kraska_menu_cache_ttl_seconds', 'type' => 'int', 'min' => 0, 'max' => 31536000],
		'providers.kraska_debug_enabled' => ['section' => 'providers', 'field' => 'kraska_debug_enabled', 'type' => 'bool', 'required' => false],
		'providers.kraska_error_backoff_seconds' => ['section' => 'providers', 'field' => 'kraska_error_backoff_seconds', 'type' => 'int', 'min' => 60, 'max' => 86400],
		'providers.krask2_download_spacing_seconds' => ['section' => 'providers', 'field' => 'krask2_download_spacing_seconds', 'type' => 'int', 'min' => 0, 'max' => 86400],
		'paths.downloads' => ['section' => 'paths', 'field' => 'downloads', 'type' => 'string', 'required' => true],
		'paths.library' => ['section' => 'paths', 'field' => 'library', 'type' => 'string', 'required' => true],
		'jellyfin.url' => ['section' => 'jellyfin', 'field' => 'url', 'type' => 'string', 'required' => false],
		'jellyfin.api_key' => ['section' => 'jellyfin', 'field' => 'api_key', 'type' => 'string', 'required' => false],
		'jellyfin.library_id' => ['section' => 'jellyfin', 'field' => 'library_id', 'type' => 'string', 'required' => false],
	];

	$normalized = [];
	$errors = [];

	foreach ($definitions as $key => $definition) {
		$section = $definition['section'];
		$field = $definition['field'];
		$required = (bool) ($definition['required'] ?? false);

		$sectionPayload = $payload[$section] ?? null;
		$rawValue = is_array($sectionPayload) ? ($sectionPayload[$field] ?? null) : null;

		if ($rawValue === null) {
			if ($required) {
				$errors[$key] = sprintf('%s.%s is required.', $section, $field);
			}

			continue;
		}

		switch ($key) {
			case 'app.base_url':
				$value = trim((string) $rawValue);
				$value = rtrim($value, '/');
				if ($value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
					$errors[$key] = 'Base URL must be a valid URL.';
					break;
				}

				$normalized[$key] = $value;
				break;

			case 'app.max_active_downloads':
				$value = filter_var($rawValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => (int) ($definition['min'] ?? 0)]]);
				if ($value === false) {
					$errors[$key] = 'Max active downloads must be a whole number greater than or equal to zero.';
					break;
				}

				$normalized[$key] = (int) $value;
				break;

			case 'app.min_free_space_gb':
				$value = filter_var($rawValue, FILTER_VALIDATE_FLOAT);
				if ($value === false || $value < (float) ($definition['min'] ?? 0)) {
					$errors[$key] = 'Minimum free space must be a positive number.';
					break;
				}

				$normalized[$key] = (float) $value;
				break;

			case 'app.default_search_limit':
				$min = (int) ($definition['min'] ?? 1);
				$max = (int) ($definition['max'] ?? 100);
				$value = filter_var($rawValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min]]);
				if ($value === false || (int) $value > $max) {
					$errors[$key] = sprintf('Default search limit must be an integer between %d and %d.', $min, $max);
					break;
				}

				$normalized[$key] = (int) $value;
				break;

			case 'aria2.max_speed_mb_s':
				$value = filter_var($rawValue, FILTER_VALIDATE_FLOAT);
				if ($value === false || $value < (float) ($definition['min'] ?? 0)) {
					$errors[$key] = 'Aria2 max speed must be a non-negative number.';
					break;
				}

				$normalized[$key] = round((float) $value, 2);
				break;

			case 'providers.kraska_menu_cache_ttl_seconds':
				$min = (int) ($definition['min'] ?? 0);
				$max = (int) ($definition['max'] ?? PHP_INT_MAX);
				$value = filter_var($rawValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min]]);
				if ($value === false || (int) $value > $max) {
					$errors[$key] = sprintf('Kra.sk menu cache TTL must be between %d and %d seconds.', $min, $max);
					break;
				}

				$normalized[$key] = (int) $value;
				break;

			case 'providers.kraska_error_backoff_seconds':
				$min = (int) ($definition['min'] ?? 60);
				$max = (int) ($definition['max'] ?? 86400);
				$value = filter_var($rawValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min]]);
				if ($value === false || (int) $value > $max) {
					$errors[$key] = sprintf('Kra.sk error backoff must be between %d and %d seconds.', $min, $max);
					break;
				}

				$normalized[$key] = (int) $value;
				break;

			case 'providers.krask2_download_spacing_seconds':
				$min = (int) ($definition['min'] ?? 0);
				$max = (int) ($definition['max'] ?? 86400);
				$value = filter_var($rawValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min]]);
				if ($value === false || (int) $value > $max) {
					$errors[$key] = sprintf('KraSk2 download spacing must be between %d and %d seconds.', $min, $max);
					break;
				}

				$normalized[$key] = (int) $value;
				break;

			case 'paths.downloads':
			case 'paths.library':
				$value = trim((string) $rawValue);
				if ($value === '') {
					$errors[$key] = 'Path cannot be empty.';
					break;
				}

				$normalized[$key] = $value;
				break;

			case 'jellyfin.url':
				$value = trim((string) $rawValue);
				if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
					$errors[$key] = 'Jellyfin URL must be empty or a valid URL.';
					break;
				}

				$normalized[$key] = $value;
				break;

			case 'jellyfin.api_key':
				$value = trim((string) $rawValue);
				$normalized[$key] = $value;
				break;

			case 'jellyfin.library_id':
				$value = trim((string) $rawValue);
				$normalized[$key] = $value;
				break;

			case 'providers.kraska_debug_enabled':
				$value = filter_var($rawValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
				if ($value === null) {
					$errors[$key] = 'Kra.sk debug must be a boolean value.';
					break;
				}
				$normalized[$key] = (bool) $value;
				break;
		}
	}

	return [$normalized, $errors];
}