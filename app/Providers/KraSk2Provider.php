<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infra\ProviderRateLimiter;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Stream-Cinema (Stremio transport) provider built around the sc_stremio.py reference script.
 *
 * The addon exposes a manifest URL (with embedded Kra.sk credentials) that returns catalogs,
 * meta details, and stream lists. This provider mirrors the Python helper by:
 *  - Building resource URLs relative to the configured manifest URL while preserving query params
 *  - Fetching catalog search results for both movies and series (series episodes are expanded on demand)
 *  - Returning stream variants that can be queued just like native Kra.sk downloads
 *  - Re-resolving stream URLs when jobs are started to avoid expiring signed URLs
 */
class KraSk2Provider implements VideoProvider, StatusCapableProvider
{
    public const DEFAULT_USER_AGENT = 'Stremio/4.4.165 (Stremio/4.4.165; x86_64.linux)';
    public const DEFAULT_HTTP_TIMEOUT = 20;
    private const DEFAULT_SERIES_EPISODE_LIMIT = 6;
    private const DEFAULT_SEARCH_ENRICH_LIMIT = 4;
    private const DEFAULT_RATE_LIMIT_MIN_SPACING = 2;
    private const DEFAULT_RATE_LIMIT_BURST_LIMIT = 12;
    private const DEFAULT_RATE_LIMIT_BURST_WINDOW = 30;
    private const RATE_LIMIT_PROVIDER_KEY = 'krask2';
    private const PREFIX_VIDEO = 'video';
    private const PREFIX_STREAM = 'stream';

    private string $manifestUrl;
    private string $userAgent;
    private int $timeoutSeconds;
    private int $seriesEpisodeSample;
    private int $searchEnrichLimit;
    private int $rateLimitMinSpacingSeconds;
    private ?int $rateLimitBurstLimit = null;
    private ?int $rateLimitBurstWindowSeconds = null;
    /** @var array<string,int> */
    private array $rateLimitOptions = [];

    private ?array $manifestCache = null;
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $catalogCache = [];
    /** @var array<string, array<string, mixed>> */
    private array $metaCache = [];
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $streamCache = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $manifestUrl = trim((string) ($config['manifest_url'] ?? ''));
        if ($manifestUrl === '') {
            throw new RuntimeException('KraSk2 manifest_url configuration is required.');
        }

        $this->manifestUrl = $manifestUrl;
        $userAgent = trim((string) ($config['user_agent'] ?? ''));
        $this->userAgent = $userAgent !== '' ? $userAgent : self::DEFAULT_USER_AGENT;

        $timeout = isset($config['http_timeout']) ? (int) $config['http_timeout'] : self::DEFAULT_HTTP_TIMEOUT;
        $timeout = max(5, min(60, $timeout));
        $this->timeoutSeconds = $timeout;

        $episodeLimit = isset($config['search_series_episode_limit']) ? (int) $config['search_series_episode_limit'] : self::DEFAULT_SERIES_EPISODE_LIMIT;
        $this->seriesEpisodeSample = max(1, min(30, $episodeLimit));

        $enrichLimit = isset($config['search_enrich_limit']) ? (int) $config['search_enrich_limit'] : self::DEFAULT_SEARCH_ENRICH_LIMIT;
        $this->searchEnrichLimit = max(0, min(50, $enrichLimit));

        $spacing = isset($config['rate_limit_min_spacing_seconds'])
            ? (int) $config['rate_limit_min_spacing_seconds']
            : self::DEFAULT_RATE_LIMIT_MIN_SPACING;
        $this->rateLimitMinSpacingSeconds = max(0, min(3600, $spacing));

        $burstLimit = isset($config['rate_limit_burst_limit'])
            ? (int) $config['rate_limit_burst_limit']
            : self::DEFAULT_RATE_LIMIT_BURST_LIMIT;
        $burstWindow = isset($config['rate_limit_burst_window_seconds'])
            ? (int) $config['rate_limit_burst_window_seconds']
            : self::DEFAULT_RATE_LIMIT_BURST_WINDOW;

        if ($burstLimit > 0 && $burstWindow > 0) {
            $this->rateLimitBurstLimit = min($burstLimit, 500);
            $this->rateLimitBurstWindowSeconds = min($burstWindow, 86400);
            $this->rateLimitOptions = [
                'burst_limit' => $this->rateLimitBurstLimit,
                'burst_window_seconds' => $this->rateLimitBurstWindowSeconds,
            ];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function search(string $query, int $limit = 50): array
    {
        $needle = trim($query);
        if ($needle === '') {
            return [];
        }

        $manifest = $this->fetchManifest();
        $catalogs = $this->filterSearchableCatalogs($manifest);
        if ($catalogs === []) {
            return [];
        }

        $results = [];
        $remaining = max(1, min(100, $limit));
        $enrichmentBudget = $this->searchEnrichLimit;

        foreach ($catalogs as $catalog) {
            $type = strtolower((string) ($catalog['type'] ?? ''));
            $catalogId = (string) ($catalog['id'] ?? '');
            if ($catalogId === '' || ($type !== 'movie' && $type !== 'series')) {
                continue;
            }

            try {
                $metas = $this->fetchCatalog($type, $catalogId, ['search' => $needle]);
            } catch (Throwable) {
                continue;
            }

            foreach ($metas as $meta) {
                if ($type === 'movie') {
                    $entry = $this->normalizeMovieSearchMeta($meta);
                    if ($entry === null) {
                        continue;
                    }

                    if ($enrichmentBudget > 0) {
                        try {
                            $streams = $this->fetchStreams('movie', $entry['video_id']);
                            $this->applyStreamHints($entry, $streams);
                        } catch (Throwable) {
                            // best effort only
                        }
                        $enrichmentBudget--;
                    }

                    $results[] = $entry['result'];
                } else {
                    $episodes = $this->expandSeriesEpisodes($meta);
                    foreach ($episodes as $episodeMeta) {
                        $entry = $this->normalizeEpisodeSearchMeta($episodeMeta);
                        if ($entry === null) {
                            continue;
                        }
                        $results[] = $entry;
                        $remaining--;
                        if ($remaining <= 0) {
                            break 3;
                        }
                    }

                    continue;
                }

                $remaining--;
                if ($remaining <= 0) {
                    break 2;
                }
            }
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * Returns stream variants for a video/episode external identifier. Used by API endpoints.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDownloadOptions(string $externalId): array
    {
        $decoded = $this->decodeToken($externalId);
        if (($decoded['kind'] ?? '') === self::PREFIX_STREAM) {
            // Already a stream token – expose as single option for convenience
            return [[
                'id' => $externalId,
                'title' => $decoded['title'] ?? 'Stream',
                'quality' => $decoded['quality'] ?? null,
                'language' => $decoded['language'] ?? null,
                'description' => $decoded['description'] ?? null,
                'source' => ['token' => $decoded],
            ]];
        }

        if (($decoded['kind'] ?? '') !== self::PREFIX_VIDEO) {
            if ($this->looksLikeUrl($externalId)) {
                return [[
                    'id' => $externalId,
                    'title' => 'Direct link',
                    'quality' => null,
                    'language' => null,
                    'source' => ['url' => $externalId],
                ]];
            }

            throw new RuntimeException('Unsupported KraSk2 identifier: ' . $externalId);
        }

        $contentType = (string) ($decoded['t'] ?? '');
        $videoId = (string) ($decoded['id'] ?? '');
        if ($contentType === '' || $videoId === '') {
            throw new RuntimeException('Incomplete KraSk2 identifier payload.');
        }

        $streams = $this->fetchStreams($contentType, $videoId);
        if ($streams === []) {
            return [];
        }

        $variants = [];
        foreach ($streams as $index => $stream) {
            $variants[] = $this->normalizeStreamVariant($contentType, $videoId, $stream, $index);
        }

        return $variants;
    }

    /**
     * {@inheritdoc}
     */
    public function resolveDownloadUrl(string $externalIdOrUrl): string|array
    {
        if ($this->looksLikeUrl($externalIdOrUrl)) {
            return $externalIdOrUrl;
        }

        $decoded = $this->decodeToken($externalIdOrUrl);
        if (($decoded['kind'] ?? '') !== self::PREFIX_STREAM) {
            throw new RuntimeException('KraSk2 external identifier does not represent a stream.');
        }

        $contentType = (string) ($decoded['t'] ?? '');
        $videoId = (string) ($decoded['id'] ?? '');
        if ($contentType === '' || $videoId === '') {
            throw new RuntimeException('Invalid KraSk2 stream identifier.');
        }

        $hash = (string) ($decoded['hash'] ?? '');
        $fallbackUrl = isset($decoded['url']) && $this->looksLikeUrl((string) $decoded['url'])
            ? (string) $decoded['url']
            : null;

        $streams = $this->fetchStreams($contentType, $videoId);
        foreach ($streams as $stream) {
            if ($hash !== '' && $this->makeStreamHash($stream) !== $hash) {
                continue;
            }

            $url = $stream['url'] ?? null;
            if (is_string($url) && $this->looksLikeUrl($url)) {
                return $url;
            }
        }

        if ($fallbackUrl !== null) {
            return $fallbackUrl;
        }

        throw new RuntimeException('Stream URL is no longer available for the requested video.');
    }

    /**
     * {@inheritdoc}
     */
    public function status(): array
    {
        try {
            $manifest = $this->fetchManifest();
            return [
                'provider' => 'krask2',
                'ok' => true,
                'manifest_id' => $manifest['id'] ?? null,
                'manifest_name' => $manifest['name'] ?? null,
                'manifest_version' => $manifest['version'] ?? null,
                'catalogs' => count(is_array($manifest['catalogs'] ?? null) ? $manifest['catalogs'] : []),
                'user_agent' => $this->userAgent,
            ];
        } catch (Throwable $exception) {
            return [
                'provider' => 'krask2',
                'ok' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Returns the raw manifest payload for UI endpoints.
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return $this->fetchManifest();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function catalogItems(string $type, string $catalogId, array $extra = []): array
    {
        return $this->fetchCatalog($type, $catalogId, $extra);
    }

    /**
     * @return array<string, mixed>
     */
    public function metaDetail(string $contentType, string $itemId): array
    {
        return $this->fetchMeta($contentType, $itemId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function streamsFor(string $contentType, string $videoId): array
    {
        return $this->fetchStreams($contentType, $videoId);
    }

    public function videoToken(string $contentType, string $videoId, array $meta = []): string
    {
        return $this->encodeVideoToken($contentType, $videoId, $meta);
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<int, array<string, mixed>>
     */
    private function filterSearchableCatalogs(array $manifest): array
    {
        $catalogs = [];
        $raw = $manifest['catalogs'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        foreach ($raw as $catalog) {
            if (!is_array($catalog)) {
                continue;
            }
            $extra = $catalog['extra'] ?? [];
            $supportsSearch = false;
            if (is_array($extra)) {
                foreach ($extra as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    if (isset($entry['name']) && (string) $entry['name'] === 'search') {
                        $supportsSearch = true;
                        break;
                    }
                }
            }

            if ($supportsSearch) {
                $catalogs[] = $catalog;
            }
        }

        return $catalogs;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchCatalog(string $type, string $catalogId, array $extra = []): array
    {
        $cacheKey = strtolower($type . '|' . $catalogId . '|' . md5(json_encode($extra)));
        if (isset($this->catalogCache[$cacheKey])) {
            return $this->catalogCache[$cacheKey];
        }

        $url = $this->buildResourceUrl('catalog', $type, $catalogId, $extra);
        $payload = $this->httpGetJson($url, 'catalog');
        $metas = [];
        if (isset($payload['metas']) && is_array($payload['metas'])) {
            $metas = $payload['metas'];
        } elseif (isset($payload['metasPreview']) && is_array($payload['metasPreview'])) {
            $metas = $payload['metasPreview'];
        }

        $this->catalogCache[$cacheKey] = $metas;

        return $metas;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchManifest(): array
    {
        if ($this->manifestCache !== null) {
            return $this->manifestCache;
        }

        $payload = $this->httpGetJson($this->manifestUrl, 'manifest');
        $this->manifestCache = $payload;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchMeta(string $contentType, string $itemId): array
    {
        $cacheKey = strtolower($contentType . '|' . $itemId);
        if (isset($this->metaCache[$cacheKey])) {
            return $this->metaCache[$cacheKey];
        }

        $url = $this->buildResourceUrl('meta', $contentType, $itemId);
        $payload = $this->httpGetJson($url, 'meta');
        $this->metaCache[$cacheKey] = $payload;

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchStreams(string $contentType, string $videoId): array
    {
        $cacheKey = strtolower($contentType . '|' . $videoId);
        if (isset($this->streamCache[$cacheKey])) {
            return $this->streamCache[$cacheKey];
        }

        $retryAfter = ProviderRateLimiter::acquire('krask2', 'streams', 1);
        if ($retryAfter !== null) {
            $sleepSeconds = max(0, min(5, $retryAfter));
            if ($sleepSeconds > 0) {
                usleep((int) ($sleepSeconds * 1_000_000));
            }
        }

        $url = $this->buildResourceUrl('stream', $contentType, $videoId);
        $payload = $this->httpGetJson($url, 'stream');
        $streams = isset($payload['streams']) && is_array($payload['streams']) ? $payload['streams'] : [];
        $this->streamCache[$cacheKey] = $streams;

        return $streams;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{video_id: string, result: array<string, mixed>}|null
     */
    private function normalizeMovieSearchMeta(array $meta): ?array
    {
        $id = (string) ($meta['id'] ?? '');
        if ($id === '') {
            return null;
        }

        $title = $meta['name'] ?? $meta['title'] ?? 'Untitled movie';
        $thumbnail = $meta['poster'] ?? $meta['posterShape'] ?? null;
        $released = $meta['releaseInfo'] ?? $meta['year'] ?? null;

        $result = [
            'provider' => 'krask2',
            'title' => (string) $title,
            'external_id' => $this->encodeVideoToken('movie', $id, [
                'title' => $title,
                'poster' => $thumbnail,
            ]),
            'thumbnail' => is_string($thumbnail) ? $thumbnail : null,
            'year' => $released,
            'size_bytes' => 0,
        ];

        return [
            'video_id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<int, array<string, mixed>>
     */
    private function expandSeriesEpisodes(array $meta): array
    {
        $seriesId = (string) ($meta['id'] ?? '');
        if ($seriesId === '') {
            return [];
        }

        $detail = $this->fetchMeta('series', $seriesId);
        $videos = $detail['meta']['videos'] ?? [];
        if (!is_array($videos) || $videos === []) {
            return [];
        }

        $normalized = [];
        foreach ($videos as $video) {
            if (!is_array($video)) {
                continue;
            }
            $video['series_title'] = $meta['name'] ?? $meta['title'] ?? null;
            $video['series_id'] = $seriesId;
            $video['series_poster'] = $meta['poster'] ?? null;
            $normalized[] = $video;
        }

        usort($normalized, static function (array $a, array $b): int {
            $seasonA = (int) ($a['season'] ?? 0);
            $seasonB = (int) ($b['season'] ?? 0);
            if ($seasonA === $seasonB) {
                $episodeA = (int) ($a['episode'] ?? 0);
                $episodeB = (int) ($b['episode'] ?? 0);
                return $episodeA <=> $episodeB;
            }

            return $seasonA <=> $seasonB;
        });

        return array_slice($normalized, 0, $this->seriesEpisodeSample);
    }

    /**
     * @param array<string, mixed> $episode
     */
    private function normalizeEpisodeSearchMeta(array $episode): ?array
    {
        $videoId = (string) ($episode['id'] ?? '');
        if ($videoId === '') {
            return null;
        }

        $seriesTitle = (string) ($episode['series_title'] ?? ($episode['series'] ?? 'Series'));
        $episodeTitle = (string) ($episode['name'] ?? $episode['title'] ?? 'Episode');
        $season = isset($episode['season']) ? (int) $episode['season'] : null;
        $episodeNumber = isset($episode['episode']) ? (int) $episode['episode'] : null;
        $label = $seriesTitle;
        if ($season !== null && $season > 0 && $episodeNumber !== null && $episodeNumber > 0) {
            $label .= sprintf(' - S%02dE%02d', $season, $episodeNumber);
        }
        $label .= ' ' . $episodeTitle;

        return [
            'provider' => 'krask2',
            'title' => trim($label),
            'external_id' => $this->encodeVideoToken('series', $videoId, [
                'title' => $label,
            ]),
            'thumbnail' => isset($episode['series_poster']) && is_string($episode['series_poster']) ? $episode['series_poster'] : null,
            'size_bytes' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<int, array<string, mixed>> $streams
     */
    private function applyStreamHints(array &$entry, array $streams): void
    {
        if ($streams === []) {
            return;
        }

        $best = $streams[0];
        $entry['result']['language'] = $best['language'] ?? $best['lang'] ?? null;
        $entry['result']['quality'] = $best['quality'] ?? $best['title'] ?? null;

        $sizeBytes = $this->extractSizeBytes($best);
        if ($sizeBytes !== null) {
            $entry['result']['size_bytes'] = $sizeBytes;
        }
    }

    /**
     * @param array<string, mixed> $stream
     */
    private function normalizeStreamVariant(string $contentType, string $videoId, array $stream, int $index): array
    {
        $title = (string) ($stream['title'] ?? $stream['name'] ?? sprintf('Option %d', $index + 1));
        $quality = $stream['quality'] ?? $stream['description'] ?? null;
        $language = $stream['language'] ?? $stream['lang'] ?? null;
        $sizeBytes = $this->extractSizeBytes($stream);
        $bitrate = $this->extractBitrateKbps($stream);

        return [
            'id' => $this->encodeStreamToken($contentType, $videoId, $stream, [
                'title' => $title,
                'quality' => $quality,
                'language' => $language,
                'bitrate' => $bitrate,
            ]),
            'title' => $title,
            'quality' => $quality,
            'language' => $language,
            'size_bytes' => $sizeBytes,
            'bitrate_kbps' => $bitrate,
            'description' => $stream['description'] ?? null,
            'source' => [
                'content_type' => $contentType,
                'video_id' => $videoId,
                'stream' => $stream,
            ],
        ];
    }

    private function extractSizeBytes(array $stream): ?int
    {
        $hints = $stream['behaviorHints'] ?? $stream['behavior_hints'] ?? null;
        if (!is_array($hints)) {
            return null;
        }
        $size = $hints['size'] ?? $hints['filesize'] ?? null;
        if ($size === null) {
            return null;
        }

        $bytes = (int) $size;
        return $bytes > 0 ? $bytes : null;
    }

    private function extractBitrateKbps(array $stream): ?int
    {
        $hints = $stream['behaviorHints'] ?? $stream['behavior_hints'] ?? null;
        if (!is_array($hints)) {
            return null;
        }

        $bitrate = $hints['bitrate'] ?? $hints['bitrateKbps'] ?? null;
        if ($bitrate === null) {
            return null;
        }

        $value = (int) $bitrate;
        if ($value <= 0) {
            return null;
        }

        if ($value > 5000 && $value < 500000) {
            // assume bits per second
            return (int) round($value / 1000);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $stream
     */
    private function encodeStreamToken(string $contentType, string $videoId, array $stream, array $meta = []): string
    {
        $payload = [
            'v' => 1,
            't' => $contentType,
            'id' => $videoId,
            'hash' => $this->makeStreamHash($stream),
        ];
        if (isset($stream['url']) && is_string($stream['url']) && $this->looksLikeUrl($stream['url'])) {
            $payload['url'] = $stream['url'];
        }
        foreach ($meta as $key => $value) {
            if ($value === null) {
                continue;
            }
            $payload[$key] = $value;
        }

        return $this->encodeToken(self::PREFIX_STREAM, $payload);
    }

    private function buildResourceUrl(string $resource, string $contentType, string $itemId, array $extra = []): string
    {
        $parsed = parse_url($this->manifestUrl);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            throw new RuntimeException('Invalid manifest URL.');
        }

        $path = $parsed['path'] ?? '';
        if ($path === '') {
            $path = '/manifest.json';
        }

        if (str_ends_with($path, '/manifest.json')) {
            $basePath = substr($path, 0, -strlen('/manifest.json'));
        } else {
            $basePath = rtrim($path, '/');
        }
        if ($basePath === '') {
            $basePath = '/';
        }

        $extraPart = '';
        $parts = [];
        foreach ($extra as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        if ($parts !== []) {
            $extraPart = '/' . implode('&', $parts);
        }

        $resourcePath = rtrim($basePath, '/') . '/' . rawurlencode($resource) . '/' . rawurlencode($contentType) . '/' . $itemId . $extraPart . '.json';

        $url = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $url .= ':' . $parsed['port'];
        }
        $url .= $resourcePath;
        if (isset($parsed['query']) && $parsed['query'] !== '') {
            $url .= '?' . $parsed['query'];
        }

        return $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function httpGetJson(string $url, string $bucket): array
    {
        $this->awaitRateLimit($bucket);

        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('Unable to initialise curl.');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $this->userAgent,
            ],
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('KraSk2 HTTP request failed: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('KraSk2 endpoint %s responded with HTTP %d', $url, $status));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid JSON payload returned by KraSk2 endpoint: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Unexpected KraSk2 response type.');
        }

        return $decoded;
    }

    private function awaitRateLimit(string $bucket): void
    {
        $bucket = strtolower(trim($bucket));
        if ($bucket === '') {
            $bucket = 'generic';
        }

        $enforceSpacing = $this->rateLimitMinSpacingSeconds > 0;
        $enforceBurst = $this->rateLimitOptions !== [];

        if (!$enforceSpacing && !$enforceBurst) {
            return;
        }

        while (true) {
            $retryAfter = ProviderRateLimiter::acquire(
                self::RATE_LIMIT_PROVIDER_KEY,
                $bucket,
                $this->rateLimitMinSpacingSeconds,
                [],
                $this->rateLimitOptions
            );
            if ($retryAfter === null) {
                return;
            }

            $sleepSeconds = max(1, $retryAfter);
            sleep($sleepSeconds);
        }
    }

    private function encodeVideoToken(string $contentType, string $videoId, array $meta = []): string
    {
        $payload = array_merge([
            'v' => 1,
            't' => $contentType,
            'id' => $videoId,
        ], $meta);

        return $this->encodeToken(self::PREFIX_VIDEO, $payload);
    }

    /**
     * @param array<string, mixed> $stream
     */
    private function makeStreamHash(array $stream): string
    {
        $parts = [
            (string) ($stream['url'] ?? ''),
            (string) ($stream['infoHash'] ?? ''),
            (string) ($stream['title'] ?? $stream['name'] ?? ''),
            (string) ($stream['behaviorHints']['filename'] ?? ''),
        ];

        return sha1(implode('|', $parts));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeToken(string $externalId): array
    {
        $parts = explode('.', $externalId, 2);
        if (count($parts) !== 2) {
            return ['kind' => 'raw', 'value' => $externalId];
        }

        [$prefix, $encoded] = $parts;
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($json === false) {
            throw new RuntimeException('Invalid KraSk2 identifier payload.');
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Malformed KraSk2 identifier payload.');
        }

        $payload['kind'] = $prefix;

        return $payload;
    }

    private function encodeToken(string $prefix, array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode KraSk2 identifier payload.');
        }

        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return $prefix . '.' . $encoded;
    }

    private function looksLikeUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }
}
