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
    private const GENERIC_TRAIL_LABELS = [
        'browse',
        'browse menu',
        'categories',
        'kategorie',
        'category',
        'filmy',
        'film',
        'movies',
        'movie',
        'serialy',
        'serial',
        'serials',
        'seriay',
        'series',
        'stream cinema',
        'stream cinema online',
        'stream cinema cz',
        'stream cinema sk',
        'stream cinema menu',
        'krask',
        'kra sk',
        'search',
        'vyhledavani',
        'vyhladavanie',
        'vyhledat',
        'vyhledavanie',
        'popular',
        'popularne',
        'popularni',
        'oblibene',
        'oblubene',
        'favourites',
        'favorites',
    ];

    private const ASCII_CHAR_MAP = [
        'á' => 'a',
        'ä' => 'a',
        'č' => 'c',
        'ď' => 'd',
        'é' => 'e',
        'ě' => 'e',
        'í' => 'i',
        'ĺ' => 'l',
        'ľ' => 'l',
        'ň' => 'n',
        'ó' => 'o',
        'ô' => 'o',
        'ö' => 'o',
        'ř' => 'r',
        'ŕ' => 'r',
        'š' => 's',
        'ť' => 't',
        'ú' => 'u',
        'ů' => 'u',
        'ü' => 'u',
        'ý' => 'y',
        'ž' => 'z',
        'Á' => 'A',
        'Ä' => 'A',
        'Č' => 'C',
        'Ď' => 'D',
        'É' => 'E',
        'Ě' => 'E',
        'Í' => 'I',
        'Ĺ' => 'L',
        'Ľ' => 'L',
        'Ň' => 'N',
        'Ó' => 'O',
        'Ô' => 'O',
        'Ö' => 'O',
        'Ř' => 'R',
        'Ŕ' => 'R',
        'Š' => 'S',
        'Ť' => 'T',
        'Ú' => 'U',
        'Ů' => 'U',
        'Ü' => 'U',
        'Ý' => 'Y',
        'Ž' => 'Z',
        'ß' => 'SS',
    ];

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
        $metadataHints = self::extractPlacementHintsFromMetadata($job);

        $categoryRaw = (string) ($job['category'] ?? '');
        $titleRaw = trim((string) ($job['title'] ?? ''));
        if ($titleRaw === '') {
            $titleRaw = pathinfo($sourcePath, PATHINFO_FILENAME) ?: basename($sourcePath);
        }

        $extension = ltrim((string) pathinfo($sourcePath, PATHINFO_EXTENSION), '.');
        $normalizedCategory = strtolower($categoryRaw);

        $episodeMatch = [];
        $hasEpisodeMarker = preg_match('/S(\d{1,2})E(\d{1,2})/i', $titleRaw, $episodeMatch) === 1;
        $hasNumericEpisodeMarker = preg_match('/^\s*\d{1,2}x\d{1,2}\b/i', $titleRaw) === 1;
        $parsedEpisode = self::parseEpisodeDetails($titleRaw);

        if ($parsedEpisode !== null) {
            if (isset($metadataHints['series']) && is_string($metadataHints['series']) && $metadataHints['series'] !== '') {
                $parsedEpisode['series'] = $metadataHints['series'];
            }
            if (isset($metadataHints['season']) && is_int($metadataHints['season'])) {
                $parsedEpisode['season'] = $metadataHints['season'];
            }
            if (isset($metadataHints['episode']) && is_int($metadataHints['episode']) && (!isset($parsedEpisode['episode']) || (int) $parsedEpisode['episode'] === 0)) {
                $parsedEpisode['episode'] = $metadataHints['episode'];
            }
            if (isset($metadataHints['episode_title']) && is_string($metadataHints['episode_title']) && $metadataHints['episode_title'] !== '') {
                $parsedEpisode['episode_title'] = $metadataHints['episode_title'];
            }

            return self::buildSeriesPlacementFromParsed($parsedEpisode, $extension, $metadataHints);
        }

        $seriesLike = ($parsedEpisode !== null)
            || $hasEpisodeMarker
            || $hasNumericEpisodeMarker
            || str_contains($normalizedCategory, 'tv')
            || str_contains($normalizedCategory, 'series')
            || str_contains($normalizedCategory, 'season')
            || str_contains($normalizedCategory, 'episode');

        if ($seriesLike) {
            return self::buildSeriesPlacementFallback($titleRaw, $episodeMatch, $extension, $metadataHints);
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

        $movieFolder = self::sanitizeSegment($fileNameBase, 'Movie');
        $fileName = self::sanitizeFilename($fileNameBase, $extension);

        return [
            'directories' => ['Movies', $movieFolder],
            'filename' => $fileName,
        ];
    }

    /**
     * Attempts to extract structured series metadata from a title string.
     *
     * @return array{series: string, season: int, episode: int, episode_title: string, suffix: ?string}|null
     */
    private static function parseEpisodeDetails(string $title): ?array
    {
        $trimmed = trim($title);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(?<season>\d{1,2})x(?<episode>\d{1,2})\s*-\s*(?<series>[^-]+?)\s*-\s*(?<episodeTitle>[^-]+?)(?:\s*-\s*(?<suffix>.+))?$/iu', $trimmed, $match) === 1) {
            return [
                'series' => trim((string) ($match['series'] ?? '')),
                'season' => (int) ($match['season'] ?? 1),
                'episode' => (int) ($match['episode'] ?? 1),
                'episode_title' => trim((string) ($match['episodeTitle'] ?? '')),
                'suffix' => isset($match['suffix']) ? trim((string) $match['suffix']) : null,
            ];
        }

        if (preg_match('/^(?<series>.+?)\s*-\s*S(?<season>\d{1,2})E(?<episode>\d{1,2})\s*-\s*(?<episodeTitle>[^-]+?)(?:\s*-\s*(?<suffix>.+))?$/iu', $trimmed, $match) === 1) {
            return [
                'series' => trim((string) ($match['series'] ?? '')),
                'season' => (int) ($match['season'] ?? 1),
                'episode' => (int) ($match['episode'] ?? 1),
                'episode_title' => trim((string) ($match['episodeTitle'] ?? '')),
                'suffix' => isset($match['suffix']) ? trim((string) $match['suffix']) : null,
            ];
        }

        if (preg_match('/S(\d{1,2})E(\d{1,2})/i', $trimmed, $match) !== 1) {
            return null;
        }

        $season = (int) ($match[1] ?? 1);
        $episode = (int) ($match[2] ?? 1);
        $parts = preg_split('/S\d{1,2}E\d{1,2}/i', $trimmed, 2);
        $series = trim($parts[0] ?? '');
        $remainder = trim($parts[1] ?? '');

        $episodeTitle = '';
        $suffix = null;
        if ($remainder !== '') {
            $segments = array_map('trim', explode('-', $remainder));
            if (isset($segments[0])) {
                $episodeTitle = $segments[0];
            }
            if (isset($segments[1])) {
                $suffix = implode('-', array_slice($segments, 1));
            }
        }

        if ($series === '') {
            $series = $trimmed;
        }

        return [
            'series' => $series,
            'season' => $season,
            'episode' => $episode,
            'episode_title' => $episodeTitle,
            'suffix' => $suffix !== null ? trim($suffix) : null,
        ];
    }

    /**
     * Builds placement details for parsed episodic metadata.
     *
     * @param array{series: string, season: int, episode: int, episode_title: string, suffix: ?string} $details
     */
    private static function buildSeriesPlacementFromParsed(array $details, string $extension, array $hints = []): array
    {
        $season = max(1, min(99, (int) ($details['season'] ?? 1)));
        $episode = max(1, min(999, (int) ($details['episode'] ?? 1)));

        if (isset($hints['season']) && is_int($hints['season'])) {
            $season = max(1, min(99, $hints['season']));
        }

        if (isset($hints['episode']) && is_int($hints['episode'])) {
            $episode = max(1, min(999, $hints['episode']));
        }

        if (isset($hints['series']) && is_string($hints['series']) && $hints['series'] !== '') {
            $details['series'] = $hints['series'];
        }

        if (isset($hints['episode_title']) && is_string($hints['episode_title']) && $hints['episode_title'] !== '') {
            $details['episode_title'] = $hints['episode_title'];
        }

        $showSegment = self::sanitizeSegment($details['series'] ?? '', 'Unknown Series');
        $seasonSegment = self::sanitizeSegment(sprintf('Season %02d', $season), sprintf('Season %02d', $season));

        $episodeCode = sprintf('S%02dE%02d', $season, $episode);
        $filenameParts = [];

        $episodeTitle = trim((string) ($details['episode_title'] ?? ''));
        if ($episodeTitle !== '') {
            $filenameParts[] = self::sanitizeLabel($episodeTitle, '');
        }

        $filenameParts[] = $episodeCode;

        $filenameParts = array_filter($filenameParts, static fn (string $part): bool => $part !== '');
        $filename = self::sanitizeFilename(implode(' ', $filenameParts), $extension);

        return [
            'directories' => ['Shows', $showSegment, $seasonSegment],
            'filename' => $filename,
        ];
    }

    private static function deriveLanguageSuffix(?string $suffix): ?string
    {
        return self::normalizeLanguageSuffixString($suffix);
    }

    private static function normalizeLanguageSuffixString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        $tokens = preg_split('/[,|]/', $normalized) ?: [];
        $labels = self::normalizeLanguageTokens($tokens, 2);

        return $labels === [] ? null : implode(', ', $labels);
    }

    /**
     * Builds placement rules for episodic (series) items.
     *
     * @param array<int, string> $episodeMatch
     */
    private static function buildSeriesPlacementFallback(string $title, array $episodeMatch, string $extension, array $hints = []): array
    {
        $season = isset($episodeMatch[1]) ? (int) $episodeMatch[1] : 1;
        $episode = isset($episodeMatch[2]) ? (int) $episodeMatch[2] : 1;

        $season = max(1, min(99, $season));
        $episode = max(1, min(999, $episode));

        $showName = trim(preg_split('/S\d{1,2}E\d{1,2}/i', $title)[0] ?? '') ?: $title;

        if (isset($hints['season']) && is_int($hints['season'])) {
            $season = max(1, min(99, $hints['season']));
        }

        if (isset($hints['episode']) && is_int($hints['episode'])) {
            $episode = max(1, min(999, $hints['episode']));
        }

        if (isset($hints['series']) && is_string($hints['series']) && $hints['series'] !== '') {
            $showName = $hints['series'];
        }

        $episodeTitle = '';
        if (preg_match('/S\d{1,2}E\d{1,2}[-:\s]*(.+)$/i', $title, $match) === 1) {
            $episodeTitle = trim((string) ($match[1] ?? ''));
        }

        if (($episodeTitle === '' || $episodeTitle === $showName) && isset($hints['episode_title']) && is_string($hints['episode_title']) && $hints['episode_title'] !== '') {
            $episodeTitle = $hints['episode_title'];
        }

        $showSegment = self::sanitizeSegment($showName, 'Unknown Series');
        $seasonSegment = self::sanitizeSegment(sprintf('Season %02d', $season), sprintf('Season %02d', $season));

        $episodeCode = sprintf('S%02dE%02d', $season, $episode);
        $filenameParts = [];

        if ($episodeTitle !== '') {
            $filenameParts[] = self::sanitizeLabel($episodeTitle, '');
        }

        $filenameParts[] = $episodeCode;

        $filenameParts = array_filter($filenameParts, static fn (string $part): bool => $part !== '');
        $filenameBase = implode(' ', $filenameParts);
        if ($filenameBase === '') {
            $filenameBase = $episodeCode;
        }

        $filename = self::sanitizeFilename($filenameBase, $extension);

        return [
            'directories' => ['Shows', $showSegment, $seasonSegment],
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

    private static function extractPlacementHintsFromMetadata(array $job): array
    {
        $rawMetadata = $job['metadata_json'] ?? ($job['metadata'] ?? null);
        $metadata = self::decodeJobMetadata($rawMetadata);
        if ($metadata === null) {
            return [];
        }

        $hints = [];

        $direct = isset($metadata['hints']) && is_array($metadata['hints']) ? $metadata['hints'] : [];

        $seriesTitle = self::sanitizeMetadataString($direct['series_title'] ?? ($direct['series'] ?? null));
        if ($seriesTitle !== null) {
            $hints['series'] = $seriesTitle;
        }

        if (isset($direct['season']) && is_numeric($direct['season'])) {
            $season = (int) $direct['season'];
            if ($season > 0 && $season < 1000) {
                $hints['season'] = $season;
            }
        }

        $seasonLabel = self::sanitizeMetadataString($direct['season_label'] ?? null);
        if ($seasonLabel !== null) {
            $hints['season_label'] = $seasonLabel;
        }

        if (isset($direct['episode']) && is_numeric($direct['episode'])) {
            $episode = (int) $direct['episode'];
            if ($episode > 0 && $episode < 2000) {
                $hints['episode'] = $episode;
            }
        }

        $episodeTitle = self::sanitizeMetadataString($direct['episode_title'] ?? null);
        if ($episodeTitle !== null) {
            $hints['episode_title'] = $episodeTitle;
        }

        $languageSuffix = self::normalizeLanguageSuffixString($direct['language_suffix'] ?? null);
        if ($languageSuffix !== null) {
            $hints['language_suffix'] = $languageSuffix;
        }

        $languageHints = self::sanitizeMetadataLanguageList($direct['languages'] ?? null);
        if ($languageHints !== []) {
            $hints['languages'] = $languageHints;
        }

        $itemMeta = isset($metadata['item']['meta']) && is_array($metadata['item']['meta']) ? $metadata['item']['meta'] : [];

        $trailLabels = self::collectMetadataTrailLabels($metadata);
        $derived = self::deriveSeriesAndSeasonFromTrail($trailLabels);
        foreach (['series', 'season', 'season_label'] as $key) {
            if (!isset($hints[$key]) && isset($derived[$key]) && $derived[$key] !== null) {
                $hints[$key] = $derived[$key];
            }
        }

        $metaSeason = $itemMeta['season'] ?? null;
        if (!isset($hints['season']) && is_numeric($metaSeason)) {
            $season = (int) $metaSeason;
            if ($season > 0 && $season < 1000) {
                $hints['season'] = $season;
            }
        }

        $metaEpisode = $itemMeta['episode'] ?? null;
        if (!isset($hints['episode']) && is_numeric($metaEpisode)) {
            $episode = (int) $metaEpisode;
            if ($episode > 0 && $episode < 2000) {
                $hints['episode'] = $episode;
            }
        }

        if (!isset($hints['languages'])) {
            $metaLanguages = self::sanitizeMetadataLanguageList($itemMeta['languages'] ?? null);
            if ($metaLanguages !== []) {
                $hints['languages'] = $metaLanguages;
            }
        }

        if (!isset($hints['language_suffix']) && isset($hints['languages'])) {
            $languageSuffix = self::normalizeLanguageSuffixString(implode(', ', $hints['languages']));
            if ($languageSuffix !== null && $languageSuffix !== '') {
                $hints['language_suffix'] = $languageSuffix;
            }
        }

        if (isset($hints['language_suffix']) && (!isset($hints['languages']) || $hints['languages'] === [])) {
            $tokens = self::normalizeLanguageTokens(preg_split('/[,|]/', $hints['language_suffix']) ?: [], 2);
            if ($tokens !== []) {
                $hints['languages'] = $tokens;
            }
        }

        return array_filter(
            $hints,
            static fn ($value): bool => $value !== null && $value !== '' && (!is_array($value) || $value !== [])
        );
    }

    private static function decodeJobMetadata(mixed $value): ?array
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

    private static function collectMetadataTrailLabels(array $metadata): array
    {
        $labels = [];
        $menu = isset($metadata['menu']) && is_array($metadata['menu']) ? $metadata['menu'] : [];

        if (isset($menu['trail_labels']) && is_array($menu['trail_labels'])) {
            foreach ($menu['trail_labels'] as $label) {
                if (is_string($label) && trim($label) !== '') {
                    $labels[] = trim($label);
                }
            }
        }

        if ($labels === [] && isset($menu['trail']) && is_array($menu['trail'])) {
            foreach ($menu['trail'] as $crumb) {
                if (!is_array($crumb)) {
                    continue;
                }
                if (isset($crumb['label']) && is_string($crumb['label']) && trim($crumb['label']) !== '') {
                    $labels[] = trim($crumb['label']);
                }
            }
        }

        if (isset($menu['branch']['label']) && is_string($menu['branch']['label']) && trim($menu['branch']['label']) !== '') {
            $labels[] = trim($menu['branch']['label']);
        }

        return $labels;
    }

    /**
     * @param array<int,string> $labels
     * @return array{series: ?string, season: ?int, season_label: ?string}
     */
    private static function deriveSeriesAndSeasonFromTrail(array $labels): array
    {
        $result = [
            'series' => null,
            'season' => null,
            'season_label' => null,
        ];

        $lastMeaningful = null;

        foreach ($labels as $label) {
            if (self::looksLikeSeasonLabel($label)) {
                if ($result['season_label'] === null) {
                    $result['season_label'] = $label;
                }
                if ($result['season'] === null) {
                    $season = self::deriveSeasonNumberFromLabel($label);
                    if ($season !== null) {
                        $result['season'] = $season;
                    }
                }
                if ($lastMeaningful !== null && $result['series'] === null) {
                    $result['series'] = $lastMeaningful;
                }
            } elseif (!self::isGenericMetadataLabel($label)) {
                $lastMeaningful = $label;
            }
        }

        if ($result['series'] === null && $lastMeaningful !== null) {
            $result['series'] = $lastMeaningful;
        }

        return $result;
    }

    private static function sanitizeMetadataLanguageList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return self::normalizeLanguageTokens($value, 2);
    }

    private static function sanitizeMetadataString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private static function looksLikeSeasonLabel(string $label): bool
    {
        $normalized = self::normalizeTrailLabel($label);
        if ($normalized === '') {
            return false;
        }

        $compact = str_replace(' ', '', $normalized);

        $seasonKeywords = ['season', 'serie', 'seria', 'sezon', 'sezona', 'rada'];
        foreach ($seasonKeywords as $keyword) {
            if (str_contains($normalized, $keyword) || str_contains($compact, $keyword)) {
                return true;
            }
        }

        if (preg_match('/\bseries\b/i', $normalized) === 1) {
            return true;
        }

        return preg_match('/S\d{1,2}/i', $label) === 1;
    }

    private static function deriveSeasonNumberFromLabel(string $label): ?int
    {
        if (preg_match('/(\d{1,2})/', $label, $match) !== 1) {
            return null;
        }

        $value = (int) $match[1];

        return $value > 0 && $value < 100 ? $value : null;
    }

    private static function isGenericMetadataLabel(string $label): bool
    {
        $normalized = self::normalizeTrailLabel($label);
        if ($normalized === '') {
            return true;
        }

        if (in_array($normalized, self::GENERIC_TRAIL_LABELS, true)) {
            return true;
        }

        return str_starts_with($normalized, 'category ') || str_starts_with($normalized, 'kategorie ');
    }

    private static function normalizeTrailLabel(string $label): string
    {
        $ascii = strtolower(self::transliterate($label));
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii ?? '') ?? '';

        return trim($ascii);
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

    private static function transliterate(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $trimmed = strtr($trimmed, self::ASCII_CHAR_MAP);

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $trimmed);
            if (is_string($converted) && $converted !== '') {
                $trimmed = $converted;
            }
        }

        return $trimmed;
    }

    /**
     * Normalises a label for safe filesystem usage.
     */
    private static function sanitizeLabel(string $value, string $fallback): string
    {
        $value = self::transliterate($value);
        if ($value === '') {
            return $fallback;
        }

        $value = preg_replace('/[^A-Za-z0-9 _().,+-]/', ' ', $value) ?? '';
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
     * @param array<int, mixed> $tokens
     * @return array<int, string>
     */
    private static function normalizeLanguageTokens(array $tokens, int $limit = 2): array
    {
        $result = [];
        $seen = [];

        foreach ($tokens as $token) {
            if (count($result) >= $limit) {
                break;
            }

            if (!is_string($token)) {
                continue;
            }

            $clean = trim($token);
            if ($clean === '') {
                continue;
            }

            $ascii = self::transliterate($clean);
            $ascii = preg_replace('/[^A-Za-z0-9+ ]/', ' ', $ascii ?? '') ?? '';
            $ascii = trim(preg_replace('/\s+/', ' ', $ascii) ?? '');
            if ($ascii === '') {
                continue;
            }

            $base = strtoupper(trim(explode('+', $ascii)[0]));
            if ($base === '') {
                $base = strtoupper($ascii);
            }

            if ($base === '') {
                continue;
            }

            if (isset($seen[$base])) {
                continue;
            }

            $seen[$base] = true;
            $result[] = $base;
        }

        return $result;
    }
}
