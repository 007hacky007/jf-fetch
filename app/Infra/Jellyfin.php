<?php

declare(strict_types=1);

namespace App\Infra;

use RuntimeException;
use Throwable;

/**
 * Provides helper utilities for interacting with the Jellyfin server and
 * managing downloaded media files inside the library tree.
 */
final class Jellyfin
{
    /**
     * Moves a freshly downloaded file into the configured Jellyfin library
     * directory tree based on job metadata.
     *
     * @param array<string, mixed> $job Download job database row.
     * @param string $sourcePath Absolute path to the downloaded file.
     *
     * @throws RuntimeException When the source file is missing or cannot be moved.
     */
    public static function moveDownloadToLibrary(array $job, string $sourcePath): string
    {
        if ($sourcePath === '' || !file_exists($sourcePath)) {
            throw new RuntimeException('Downloaded file missing: ' . $sourcePath);
        }

        $libraryRoot = (string) Config::get('paths.library');
        self::ensureDirectory($libraryRoot);

    $placement = self::inferMediaPlacement($job, $sourcePath);

        $targetDir = rtrim($libraryRoot, '/');
        foreach ($placement['directories'] as $segment) {
            $targetDir .= '/' . $segment;
            self::ensureDirectory($targetDir);
        }

        $targetPath = $targetDir . '/' . $placement['filename'];

        if (file_exists($targetPath)) {
            $base = pathinfo($placement['filename'], PATHINFO_FILENAME);
            $extension = pathinfo($placement['filename'], PATHINFO_EXTENSION);
            $suffix = '-' . time();
            $conflictName = $base . $suffix;
            $targetPath = $targetDir . '/' . self::sanitizeFilename($conflictName, $extension);
        }

        if (!@rename($sourcePath, $targetPath)) {
            throw new RuntimeException('Failed to move downloaded file into library.');
        }

        @chmod($targetPath, 0644);

        return $targetPath;
    }

    /**
     * Triggers a Jellyfin library refresh to discover newly moved media.
     */
    public static function refreshLibrary(): void
    {
        $url = rtrim((string) Config::get('jellyfin.url'), '/');
        $apiKey = (string) Config::get('jellyfin.api_key');
        $libraryId = '';
        if (Config::has('jellyfin.library_id')) {
            $libraryId = (string) Config::get('jellyfin.library_id');
        }

        if ($url === '' || $apiKey === '') {
            error_log('[jellyfin] Skipping refresh due to missing configuration.');

            return;
        }

        // Prefer targeted library refresh when library ID provided, else global refresh fallback
        if ($libraryId !== '') {
            $endpoint = sprintf(
                '%s/Items/%s/Refresh?Recursive=true&ImageRefreshMode=Default&MetadataRefreshMode=Default&ReplaceAllImages=false&RegenerateTrickplay=false&ReplaceAllMetadata=false',
                $url,
                rawurlencode($libraryId)
            );
            $headers = [ 'X-Emby-Token: ' . $apiKey ];
        } else {
            // Legacy global refresh; using old style query param
            $endpoint = $url . '/Library/Refresh?api_key=' . urlencode($apiKey);
            $headers = [];
        }

        $handle = curl_init($endpoint);
        if ($handle === false) {
            error_log('[jellyfin] Failed to initialize cURL handle.');

            return;
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        try {
            curl_exec($handle);
        } catch (Throwable $exception) {
            error_log('[jellyfin] Refresh request failed: ' . $exception->getMessage());
        } finally {
            curl_close($handle);
        }
    }

    /**
     * Determines the directory structure and filename for a downloaded item.
     *
     * @param array<string, mixed> $job
     *
     * @return array{directories: array<int, string>, filename: string}
     */
    private static function inferMediaPlacement(array $job, string $sourcePath): array
    {
        $categoryRaw = (string) ($job['category'] ?? '');
        $titleRaw = trim((string) ($job['title'] ?? ''));
        if ($titleRaw === '') {
            $titleRaw = pathinfo($sourcePath, PATHINFO_FILENAME) ?: basename($sourcePath);
        }

        $extension = ltrim((string) pathinfo($sourcePath, PATHINFO_EXTENSION), '.');
        $normalizedCategory = strtolower($categoryRaw);

        $episodeMatch = [];
        $hasEpisodeMarker = preg_match('/S(\d{1,2})E(\d{1,2})/i', $titleRaw, $episodeMatch) === 1;

        $seriesLike = $hasEpisodeMarker
            || str_contains($normalizedCategory, 'tv')
            || str_contains($normalizedCategory, 'series')
            || str_contains($normalizedCategory, 'season')
            || str_contains($normalizedCategory, 'episode');

        if ($seriesLike) {
            return self::buildSeriesPlacement($titleRaw, $episodeMatch, $extension);
        }

        return self::buildMoviePlacement($titleRaw, $extension);
    }

    /**
     * Builds placement rules for movie-style items.
     */
    private static function buildMoviePlacement(string $title, string $extension): array
    {
        $year = self::extractYear($title);
        $baseTitle = trim($title);

        if ($year !== null) {
            $baseTitle = trim((string) preg_replace('/\(?' . $year . '\)?/i', '', $baseTitle));
        }

        if ($baseTitle === '') {
            $baseTitle = 'Movie';
        }

        $fileNameBase = $baseTitle;

        if ($year !== null) {
            $fileNameBase = sprintf('%s (%d)', $baseTitle, $year);
        }

        $initialSegment = self::sanitizeSegment(self::determineMovieInitial($baseTitle), 'Other');
        $fileName = self::sanitizeFilename($fileNameBase, $extension);

        return [
            'directories' => ['Movie', $initialSegment],
            'filename' => $fileName,
        ];
    }

    /**
     * Builds placement rules for episodic (series) items.
     *
     * @param array<int, string> $episodeMatch
     */
    private static function buildSeriesPlacement(string $title, array $episodeMatch, string $extension): array
    {
        $season = isset($episodeMatch[1]) ? (int) $episodeMatch[1] : 1;
        $episode = isset($episodeMatch[2]) ? (int) $episodeMatch[2] : 1;

        $season = max(1, min(99, $season));
        $episode = max(1, min(999, $episode));

        $showName = trim(preg_split('/S\d{1,2}E\d{1,2}/i', $title)[0] ?? '') ?: $title;

        $episodeTitle = '';
        if (preg_match('/S\d{1,2}E\d{1,2}[-:\s]*(.+)$/i', $title, $match) === 1) {
            $episodeTitle = trim((string) ($match[1] ?? ''));
        }

    $showSegment = self::sanitizeSegment($showName, 'Unknown Series');
        $seasonSegment = self::sanitizeSegment(sprintf('Season %02d', $season), sprintf('Season %02d', $season));

        $filenameParts = [
            $showSegment,
            sprintf('S%02dE%02d', $season, $episode),
        ];

        if ($episodeTitle !== '') {
            $filenameParts[] = self::sanitizeLabel($episodeTitle, '');
        }

        $filenameParts = array_filter($filenameParts, static fn (string $part): bool => $part !== '');
        $filenameBase = implode(' - ', $filenameParts);
        if ($filenameBase === '') {
            $filenameBase = sprintf('Episode S%02dE%02d', $season, $episode);
        }

        $filename = self::sanitizeFilename($filenameBase, $extension);

        return [
            'directories' => ['Series', $showSegment, $seasonSegment],
            'filename' => $filename,
        ];
    }

    /**
     * Extracts a four-digit year from a title when available.
     */
    private static function extractYear(string $title): ?int
    {
        if (preg_match('/\b(19|20)\d{2}\b/', $title, $match) === 1) {
            return (int) $match[0];
        }

        return null;
    }

    /**
     * Ensures the target directory exists before attempting filesystem writes.
     */
    private static function ensureDirectory(string $path): void
    {
        if ($path === '') {
            throw new RuntimeException('Directory path cannot be empty.');
        }

        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create directory: ' . $path);
        }
    }

    /**
     * Sanitizes a path segment to avoid directory traversal issues.
     */
    private static function sanitizeSegment(string $value, string $fallback = 'Misc'): string
    {
        return self::sanitizeLabel($value, $fallback);
    }

    /**
     * Normalises a label for safe filesystem usage.
     */
    private static function sanitizeLabel(string $value, string $fallback): string
    {
        $value = trim($value);
        $value = preg_replace('/[^A-Za-z0-9 _().-]/', ' ', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        $value = trim($value);

        return $value === '' ? $fallback : $value;
    }

    /**
     * Normalises a filename while preserving the provided extension.
     */
    private static function sanitizeFilename(string $baseName, string $extension): string
    {
        $base = self::sanitizeLabel($baseName, 'Media');
        $extension = trim($extension);

        if ($extension === '') {
            return $base;
        }

        return $base . '.' . ltrim($extension, '.');
    }

    /**
     * Determines the appropriate second-level directory name for movies based on their initial.
     */
    private static function determineMovieInitial(string $title): string
    {
        $normalized = self::sanitizeLabel($title, 'Movie');

        if (preg_match('/[A-Za-z]/', $normalized, $match) === 1) {
            return strtoupper($match[0]);
        }

        if (preg_match('/\d/', $normalized) === 1) {
            return '0-9';
        }

        return 'Other';
    }
}
