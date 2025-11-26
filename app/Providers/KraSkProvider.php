<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infra\ProviderRateLimiter;
use RuntimeException;
use JsonException;

/**
 * Kra.sk implementation of the VideoProvider interface.
 *
 * Reverse–engineered from the Kodi Stream-Cinema addon (kraska.py + sc.py) to support:
 *  - Username/password based login (POST /api/user/login) returning session_id
 *  - File search (POST /api/file/list) via 'filter' parameter
 *  - Download URL resolution (POST /api/file/download) returning data.link
 *  - Subscription validation (POST /api/user/info) providing days_left & subscribed_until
 *
 * NOTES
 *  - The original addon maintains a checksum of credentials to detect changes; here we simply
 *    invalidate the cached session when username/password differs from the in-memory copy.
 *  - All credentials are stored encrypted (handled upstream by ProviderSecrets); we only keep
 *    runtime copies inside this object.
 */
class KraSkProvider implements VideoProvider, StatusCapableProvider
{
    private const API_BASE = 'https://api.kra.sk';
    private const SC_BASE = 'https://stream-cinema.online/kodi';
    private const DEFAULT_SC_RATE_LIMIT_SPACING = 2;
    private const DEFAULT_SC_RATE_LIMIT_BURST_LIMIT = 12;
    private const DEFAULT_SC_RATE_LIMIT_BURST_WINDOW = 30;
    private const RATE_LIMIT_PROVIDER_KEY = 'kraska';

    /** @var array<string,mixed> */
    private array $config;

    /**
     * Optional adapter hook for HTTP requests (endpoint, payload, requireAuth, attachSession, mutateSession) => array response.
     * @var callable|null
     */
    private $httpAdapter = null;
    /** @var callable|null */
    private $scHttpGet = null; // test override for Stream-Cinema GET JSON

    private ?string $sessionId = null;
    private ?array $userInfoCache = null; // result of /api/user/info 'data'
    private ?int $lastLoginTs = null;
    private ?string $scToken = null; // Stream-Cinema auth token (X-AUTH-TOKEN)
    private ?string $scUuid = null;  // Stable UUID per instance
    private ?int $lastScTokenTs = null;
    /** @var array<int,string> */
    private array $scFetchQueue = []; // queued Stream-Cinema detail paths waiting for rate limit<br/>
    private ?int $lastIdentFetchTs = null; // timestamp of last successful fetchStreamCinemaItem real request
    private int $streamCinemaMinSpacingSeconds = self::DEFAULT_SC_RATE_LIMIT_SPACING;
    private ?int $streamCinemaBurstLimit = self::DEFAULT_SC_RATE_LIMIT_BURST_LIMIT;
    private ?int $streamCinemaBurstWindowSeconds = self::DEFAULT_SC_RATE_LIMIT_BURST_WINDOW;
    /** @var array<string,int> */
    private array $streamCinemaRateLimitOptions = [];

    /**
     * @param array<string,mixed> $config Expected keys: username, password
     */
    public function __construct(array $config = [], ?callable $httpAdapter = null, ?callable $scHttpGet = null)
    {
        $this->config = $config;
        $this->httpAdapter = $httpAdapter;
        $this->scHttpGet = $scHttpGet;
        // Honor provided static uuid if present so that upstream services that bind auth tokens
        // to a client identifier remain stable across restarts.
        if (isset($config['uuid']) && is_string($config['uuid']) && $config['uuid'] !== '') {
            $this->scUuid = $config['uuid'];
        }

        $spacing = isset($config['rate_limit_min_spacing_seconds'])
            ? (int) $config['rate_limit_min_spacing_seconds']
            : self::DEFAULT_SC_RATE_LIMIT_SPACING;
        $this->streamCinemaMinSpacingSeconds = max(0, min(3600, $spacing));

        $burstLimit = isset($config['rate_limit_burst_limit'])
            ? (int) $config['rate_limit_burst_limit']
            : self::DEFAULT_SC_RATE_LIMIT_BURST_LIMIT;
        $burstWindow = isset($config['rate_limit_burst_window_seconds'])
            ? (int) $config['rate_limit_burst_window_seconds']
            : self::DEFAULT_SC_RATE_LIMIT_BURST_WINDOW;

        if ($burstLimit > 0 && $burstWindow > 0) {
            $this->streamCinemaBurstLimit = min($burstLimit, 500);
            $this->streamCinemaBurstWindowSeconds = min($burstWindow, 86400);
            $this->streamCinemaRateLimitOptions = [
                'burst_limit' => $this->streamCinemaBurstLimit,
                'burst_window_seconds' => $this->streamCinemaBurstWindowSeconds,
            ];
        } else {
            $this->streamCinemaBurstLimit = null;
            $this->streamCinemaBurstWindowSeconds = null;
            $this->streamCinemaRateLimitOptions = [];
        }
    }

    /**
     * Search files by textual filter.
     * Kra.sk API endpoint: POST /api/file/list { data: { parent: null, filter: "query" } }
     * Returns structure with 'data' array; each element includes at least 'ident' and 'name'.
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query, int $limit = 50): array
    {
        $query = trim($query);
        if ($query === '') { return []; }
        $menu = [];
        $scError = null;
        $searchCategories = ['search','search-movie','search-series'];
        $peopleCategory = 'search-people';
        $aggregated = [];
        try {
            foreach ($searchCategories as $category) {
                $url = self::SC_BASE . '/Search/' . $category;
                $params = $this->buildStreamCinemaParams(['search' => $query, 'id' => $category]);
                $data = $this->httpGetJson($url . '?' . $params, 15, true, 'catalog');
                $part = is_array($data['menu'] ?? null) ? $data['menu'] : [];
                if (!empty($part)) { $aggregated = array_merge($aggregated, $part); }
                if (count($aggregated) >= $limit) { break; }
            }
            if (count($aggregated) < $limit && strlen($query) > 2) {
                $url = self::SC_BASE . '/Search/' . $peopleCategory;
                $params = $this->buildStreamCinemaParams(['search' => $query, 'id' => $peopleCategory, 'ms' => '1']);
                $data = $this->httpGetJson($url . '?' . $params, 15, true, 'catalog');
                $part = is_array($data['menu'] ?? null) ? $data['menu'] : [];
                if (!empty($part)) { $aggregated = array_merge($aggregated, $part); }
            }
            $menu = $aggregated;
    } catch (\Throwable $e) { $scError = $e; if (($this->config['debug'] ?? false) && function_exists('fwrite')) { @fwrite(STDERR, "SEARCH ERROR: ".$e->getMessage()."\n"); } }
        if ((empty($menu) && $scError !== null) || (is_array($menu) && count($menu) === 0 && strlen($query) >= 3)) {
            try {
                $payload = ['data' => ['parent' => null, 'filter' => $query]];
                $resp = $this->apiPost('/api/file/list', $payload, requireAuth: true);
                $menu = is_array($resp['data'] ?? null) ? $resp['data'] : [];
            } catch (\Throwable $e) { if ($scError !== null) { throw $scError; } throw $e; }
        }
        if (!is_array($menu)) { return []; }
        $enrichCap = (int) ($this->config['enrich_limit'] ?? 8);
        $enriched = 0;
        $results = [];
        foreach ($menu as $entry) {
            if (!is_array($entry)) { continue; }
            $type = strtolower((string) ($entry['type'] ?? ''));
            if ($type !== '' && $type !== 'video') { continue; }
            $scId = $entry['id'] ?? null;
            $playUrl = is_string($entry['url'] ?? null)
                ? $entry['url']
                : ((is_string($scId) && $scId !== '') || (is_int($scId) || is_float($scId))
                    ? '/Play/' . $scId
                    : null);
            $ident = $playUrl;
            $name = $this->deriveTitle($entry);
            $streamInfo = $entry['stream_info'] ?? [];
            $videoInfo = is_array($streamInfo) ? ($streamInfo['video'] ?? []) : [];
            // Extract video codec and resolution
            $videoCodec = is_array($videoInfo) && isset($videoInfo['codec']) ? $videoInfo['codec'] : null;
            $width = isset($videoInfo['width']) && is_numeric($videoInfo['width']) ? (int)$videoInfo['width'] : null;
            $height = isset($videoInfo['height']) && is_numeric($videoInfo['height']) ? (int)$videoInfo['height'] : null;
            
            // Build quality string from height and codec
            $quality = null;
            if ($height !== null) {
                $quality = $height . 'p' . ($videoCodec !== null && $videoCodec !== '' ? ' ' . $videoCodec : '');
            } elseif ($videoCodec !== null && $videoCodec !== '') {
                $quality = $videoCodec;
            }
            
            // Extract language info
            $langKeys = [];
            if (is_array($streamInfo) && isset($streamInfo['langs']) && is_array($streamInfo['langs'])) { 
                $langKeys = array_keys($streamInfo['langs']); 
            }
            $lang = empty($langKeys) ? null : implode(',', $langKeys);
            
            // Extract duration
            $duration = null;
            if (is_array($videoInfo) && isset($videoInfo['duration']) && is_numeric($videoInfo['duration'])) {
                $duration = (int) $videoInfo['duration'];
            } elseif (isset($entry['info']['duration']) && is_numeric($entry['info']['duration'])) {
                $duration = (int) $entry['info']['duration'];
            }
            
            // Extract audio info
            $audioInfo = is_array($streamInfo) ? ($streamInfo['audio'] ?? []) : [];
            $audioCodec = is_array($audioInfo) && isset($audioInfo['codec']) ? $audioInfo['codec'] : null;
            $audioChannels = is_array($audioInfo) && isset($audioInfo['channels']) ? (int)$audioInfo['channels'] : null;
            
            // Extract FPS
            $fps = isset($streamInfo['fps']) && (is_numeric($streamInfo['fps']) || is_string($streamInfo['fps'])) ? (string)$streamInfo['fps'] : null;
            
            // Get initial values
            $bitrate = $entry['bitrate'] ?? null;
            $size = (int) ($entry['size'] ?? 0);
            
            // First priority: If we have actual size and duration, calculate real bitrate
            if ($size > 0 && $duration !== null && $duration > 0) {
                // size in bytes -> bits / duration seconds -> kbps
                $bitrate = (int) round(($size * 8) / $duration / 1000);
            }
            // Second priority: If no size but have duration, estimate both bitrate and size
            elseif ($size === 0 && $duration !== null && $duration > 0 && $height !== null) {
                // Estimate bitrate based on resolution (kbps)
                if ($height >= 2160) { // 4K
                    $bitrate = $videoCodec === 'hevc' ? 15000 : 25000;
                } elseif ($height >= 1080) { // 1080p
                    $bitrate = $videoCodec === 'hevc' ? 5000 : 8000;
                } elseif ($height >= 720) { // 720p
                    $bitrate = $videoCodec === 'hevc' ? 2500 : 4000;
                } else { // SD
                    $bitrate = 1500;
                }
                // Calculate size: bitrate (kbps) * duration (seconds) * 1000 / 8
                $size = (int) (($bitrate * $duration * 1000) / 8);
            }
            
            // Extract aspect ratio
            $aspect = null;
            if (isset($videoInfo['aspect'])) {
                $aspect = $videoInfo['aspect'];
            } elseif (isset($videoInfo['ratio'])) {
                $aspect = $videoInfo['ratio'];
            }
            
            // Build file entry with all extracted metadata
            $file = [ 
                'ident' => $ident, 
                'name' => $name, 
                'size' => $size, 
                'quality' => $quality, 
                'language' => $lang, 
                'bitrate' => $bitrate, 
                'source_entry' => $entry 
            ];
            
            // Attach all metadata
            if ($duration !== null) { $file['duration'] = $duration; }
            if ($fps !== null) { $file['fps'] = $fps; }
            if ($videoCodec !== null) { $file['video_codec'] = $videoCodec; }
            if ($audioCodec !== null) { $file['audio_codec'] = $audioCodec; }
            if ($audioChannels !== null) { $file['audio_channels'] = $audioChannels; }
            if ($width !== null) { $file['width'] = $width; }
            if ($height !== null) { $file['height'] = $height; }
            if ($aspect !== null) { $file['aspect'] = $aspect; }
            // Enrich with additional metadata if needed
            if ($file['ident'] !== null && $enriched < $enrichCap && 
                ($file['size'] === 0 || $file['width'] === null || $file['video_codec'] === null)) {
                try {
                    $detail = $this->fetchStreamCinemaItem((string)$file['ident']);
                    $meta = $this->extractKraStreamMeta($detail);
                    if (is_array($meta)) {
                        // Extract Kra.sk identifier
                        if (isset($meta['ident']) && is_string($meta['ident'])) { 
                            $file['kra_ident'] = $meta['ident']; 
                        }
                        
                        // Extract file size if available
                        if (isset($meta['size']) && is_numeric($meta['size']) && $meta['size'] > 0) { 
                            $file['size'] = (int)$meta['size']; 
                        }
                        
                        // Extract bitrate if available
                        if (isset($meta['bitrate']) && is_numeric($meta['bitrate'])) { 
                            $file['bitrate'] = (int)$meta['bitrate']; 
                        }
                        
                        // Extract video metadata
                        $videoMeta = $meta['video'] ?? [];
                        if (is_array($videoMeta)) {
                            if (isset($videoMeta['codec']) && $file['video_codec'] === null) {
                                $file['video_codec'] = $videoMeta['codec'];
                            }
                            if (isset($videoMeta['width']) && $file['width'] === null) {
                                $file['width'] = (int)$videoMeta['width'];
                            }
                            if (isset($videoMeta['height']) && $file['height'] === null) {
                                $file['height'] = (int)$videoMeta['height'];
                            }
                            if (isset($videoMeta['duration']) && $file['duration'] === null) {
                                $file['duration'] = (int)$videoMeta['duration'];
                            }
                            if (isset($videoMeta['aspect']) && $file['aspect'] === null) {
                                $file['aspect'] = $videoMeta['aspect'];
                            } elseif (isset($videoMeta['ratio']) && $file['aspect'] === null) {
                                $file['aspect'] = $videoMeta['ratio'];
                            }
                        }
                        
                        // Extract audio metadata
                        $audioMeta = $meta['audio'] ?? [];
                        if (is_array($audioMeta)) {
                            if (isset($audioMeta['codec']) && $file['audio_codec'] === null) {
                                $file['audio_codec'] = $audioMeta['codec'];
                            }
                            if (isset($audioMeta['channels']) && $file['audio_channels'] === null) {
                                $file['audio_channels'] = (int)$audioMeta['channels'];
                            }
                        }
                        
                        // Extract FPS
                        if (isset($meta['fps']) && $file['fps'] === null) { 
                            $file['fps'] = (string)$meta['fps']; 
                        }
                        
                        // Recalculate size if we now have duration and still no size
                        if ($file['size'] === 0 && isset($file['duration']) && $file['duration'] > 0) {
                            if ($file['bitrate'] !== null && $file['bitrate'] > 0) {
                                // Use actual bitrate
                                $file['size'] = (int) (($file['bitrate'] * $file['duration'] * 1000) / 8);
                            } elseif (isset($file['height'])) {
                                // Estimate bitrate from resolution
                                $estimatedBitrate = 1500; // default SD
                                if ($file['height'] >= 2160) {
                                    $estimatedBitrate = $file['video_codec'] === 'hevc' ? 15000 : 25000;
                                } elseif ($file['height'] >= 1080) {
                                    $estimatedBitrate = $file['video_codec'] === 'hevc' ? 5000 : 8000;
                                } elseif ($file['height'] >= 720) {
                                    $estimatedBitrate = $file['video_codec'] === 'hevc' ? 2500 : 4000;
                                }
                                $file['size'] = (int) (($estimatedBitrate * $file['duration'] * 1000) / 8);
                                if ($file['bitrate'] === null) {
                                    $file['bitrate'] = $estimatedBitrate;
                                }
                            }
                        }
                        
                        // Rebuild quality string if it was missing
                        if (($file['quality'] === null || $file['quality'] === '') && isset($file['height'])) {
                            $file['quality'] = $file['height'] . 'p' . (!empty($file['video_codec']) ? ' ' . $file['video_codec'] : '');
                        }
                    }
                    $enriched++;
                } catch (\Throwable $e) { 
                    // Log enrichment errors in debug mode
                    if (($this->config['debug'] ?? false) === true) {
                        $this->debugLog('[ENRICH ERROR] ' . $file['ident'] . ': ' . $e->getMessage());
                    }
                }
            }
            if ($file['ident'] === null) { continue; }
            $results[] = $this->normalizeFile($file);
            if (count($results) >= $limit) { break; }
        }
        return $results;
    }

    /**
     * Browse the Stream-Cinema hierarchical menu using Kra.sk credentials.
     * The response mirrors Kodi's structure but is normalised for the UI.
     *
     * @return array<string,mixed>
     */
    public function browseMenu(string $path = '/'): array
    {
        $normalizedPath = $this->normalizeMenuPath($path);
        $query = $this->buildStreamCinemaParams([]);
        $baseUrl = rtrim(self::SC_BASE, '/');
        $url = $baseUrl . $normalizedPath;
        $url .= str_contains($normalizedPath, '?') ? '&' . $query : '?' . $query;

        $response = $this->httpGetJson($url, 20, true, 'catalog');

        $items = [];
        $rawMenu = $response['menu'] ?? [];
        if (is_array($rawMenu)) {
            foreach ($rawMenu as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $items[] = $this->normalizeMenuEntry($entry);
            }
        }

        $result = [
            'path' => $normalizedPath,
            'title' => $this->extractMenuTitle($response),
            'items' => $items,
        ];

        $filter = $response['filter'] ?? null;
        if (is_array($filter) && $filter !== []) {
            $result['filter'] = $filter;
        }

        return $result;
    }

    /**
     * Return all Kra.sk download variants associated with a menu entry.
     * Mirrors Kodi's behaviour of presenting multiple quality/codec options when available.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listDownloadOptions(string $externalIdOrUrl): array
    {
        $value = trim($externalIdOrUrl);
        if ($value === '') {
            return [];
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return [$this->buildFallbackVariant($value, null)];
        }

        $detail = null;
        $path = null;

        if (str_starts_with($value, '/')) {
            $path = $this->normalizeMenuPath($value);
        } elseif (preg_match('#^Play/\d+$#i', $value) === 1) {
            $path = $this->normalizeMenuPath('/' . $value);
        } elseif (preg_match('#^\d+$#', $value) === 1) {
            $path = $this->normalizeMenuPath('/Play/' . $value);
        }

        if ($path !== null) {
            try {
                $detail = $this->fetchStreamCinemaItem($path);
            } catch (RateLimitDeferredException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                if (($this->config['debug'] ?? false) === true) {
                    $this->debugLog('[OPTIONS] Failed to fetch detail for ' . $path . ': ' . $exception->getMessage());
                }
            }
        }

        if (is_array($detail)) {
            $variants = $this->extractKraStreamVariants($detail);
            if ($variants !== []) {
                return $variants;
            }

            $fallbackIdent = $this->extractKraIdentFromDetail($detail);
            if (is_string($fallbackIdent) && $fallbackIdent !== '') {
                return [$this->buildFallbackVariant($fallbackIdent, $detail)];
            }
        }

        return [$this->buildFallbackVariant($value, $detail)];
    }

    /**
     * Resolve a download URL from a file ident.
     * Kra.sk endpoint: POST /api/file/download { data: { ident: <id> } }
     * Returns { data: { link: "https://..." } }
     *
     * @return string
     */
    public function resolveDownloadUrl(string $externalIdOrUrl): string|array
    {
        $debug = ($this->config['debug'] ?? false) === true;
        
        if ($debug) {
            $this->debugLog('[DOWNLOAD] Starting resolution for: ' . $externalIdOrUrl);
        }
        
        // If it's already a fully qualified URL just return.
        if (str_starts_with($externalIdOrUrl, 'http://') || str_starts_with($externalIdOrUrl, 'https://')) {
            if ($debug) {
                $this->debugLog('[DOWNLOAD] Already a full URL, returning as-is');
            }
            return $externalIdOrUrl;
        }

        $ident = $externalIdOrUrl;
        $kraIdent = null;
        
        // If value looks like a Stream-Cinema plugin path (starts with '/') fetch details to extract ident.
        if (str_starts_with($externalIdOrUrl, '/')) {
            // Recognize canonical /Play/{id} path and extract id directly without detail fetch.
            if (preg_match('#^/Play/(\d+)$#', $externalIdOrUrl, $m)) {
                $scId = $m[1];
                if ($debug) {
                    $this->debugLog('[DOWNLOAD] Detected /Play/ format with SC ID: ' . $scId);
                }
                // Need to fetch detail to get actual Kra.sk ident
                try {
                    $detail = $this->fetchStreamCinemaItem($externalIdOrUrl);
                    if ($debug) {
                        $this->debugLog('[DOWNLOAD] Fetched Stream-Cinema detail, keys: ' . implode(', ', array_keys($detail)));
                    }
                    $kraStream = $this->extractKraStreamMeta($detail);
                    if ($kraStream !== null && isset($kraStream['url'])) {
                        $streamUrl = $kraStream['url'];
                        if ($debug) {
                            $this->debugLog('[DOWNLOAD] Found Kra.sk stream url: ' . $streamUrl);
                        }
                        // Call Stream-Cinema's /ws2/ endpoint to get actual ident
                        try {
                            $scResponse = $this->callStreamCinemaApi($streamUrl);
                            $actualIdent = $scResponse['ident'] ?? null;
                            if ($actualIdent !== null && $actualIdent !== '') {
                                $ident = $actualIdent;
                                if ($debug) {
                                    $this->debugLog('[DOWNLOAD] Got actual ident from Stream-Cinema /ws2/: ' . $ident);
                                }
                            } else {
                                if ($debug) {
                                    $this->debugLog('[DOWNLOAD] Stream-Cinema response missing ident, trying sid fallback');
                                }
                                // Fallback to sid if ident not available
                                $ident = $kraStream['sid'] ?? $scId;
                            }
                        } catch (\Throwable $e) {
                            if ($debug) {
                                $this->debugLog('[DOWNLOAD] Failed to call Stream-Cinema /ws2/: ' . $e->getMessage());
                            }
                            // Fallback to sid
                            $ident = $kraStream['sid'] ?? $scId;
                        }
                    } else {
                        // Try with the numeric ID directly
                        $ident = $scId;
                        if ($debug) {
                            $this->debugLog('[DOWNLOAD] No Kra.sk stream found in strms, falling back to SC ID: ' . $ident);
                        }
                    }
                } catch (RateLimitDeferredException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    // Fallback to numeric ID
                    $ident = $scId;
                    if ($debug) {
                        $this->debugLog('[DOWNLOAD] Exception fetching detail, falling back to SC ID: ' . $scId . ' - Error: ' . $e->getMessage());
                    }
                }
            } else {
                // Fallback: fetch detail to extract provider ident (costly)
                if ($debug) {
                    $this->debugLog('[DOWNLOAD] Non-/Play/ format, attempting detail fetch');
                }
                try {
                    $detail = $this->fetchStreamCinemaItem($externalIdOrUrl);
                    $kraIdent = $this->extractKraIdentFromDetail($detail);
                    if ($kraIdent !== null) { 
                        $ident = $kraIdent;
                        if ($debug) {
                            $this->debugLog('[DOWNLOAD] Extracted Kra.sk ident: ' . $ident);
                        }
                    } else {
                        if ($debug) {
                            $this->debugLog('[DOWNLOAD] No Kra.sk ident found in detail');
                        }
                    }
                } catch (RateLimitDeferredException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    // Leave ident as original path; downstream API call may still succeed
                    if ($debug) {
                        $this->debugLog('[DOWNLOAD] Failed to extract ident: ' . $e->getMessage());
                    }
                }
            }
        }

        if (is_string($ident) && $ident !== '' && $this->isLikelyNumericIdent($ident)) {
            if ($debug) {
                $this->debugLog('[DOWNLOAD] Numeric ident detected, attempting Stream-Cinema lookup prior to API call');
            }
            try {
                $derivedIdent = $this->deriveIdentFromStreamCinema($externalIdOrUrl, $ident);
                if (is_string($derivedIdent) && $derivedIdent !== '' && $derivedIdent !== $ident) {
                    $ident = $derivedIdent;
                    if ($debug) {
                        $this->debugLog('[DOWNLOAD] Derived Kra.sk ident before API call: ' . $ident);
                    }
                }
            } catch (RateLimitDeferredException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                if ($debug) {
                    $this->debugLog('[DOWNLOAD] Pre-flight ident lookup failed: ' . $exception->getMessage());
                }
            }
        }

        if ($debug) {
            $this->debugLog('[DOWNLOAD] Final ident before API call: ' . $ident);
        }

        $payload = [ 'data' => [ 'ident' => $ident ] ];
        $attempt = 0;

        while (true) {
            if ($debug) {
                $this->debugLog('[DOWNLOAD] Attempt ' . ($attempt + 1) . ' with ident: ' . $payload['data']['ident']);
            }

            try {
                $response = $this->apiPost('/api/file/download', $payload, requireAuth: true);
                if ($debug) {
                    $this->debugLog('[DOWNLOAD] API response keys: ' . implode(', ', array_keys($response)));
                }
                break;
            } catch (KraSkApiException $exception) {
                if ($debug) {
                    $this->debugLog('[DOWNLOAD] API returned KraSkApiException: ' . $exception->getMessage());
                }

                if ($attempt >= 1) {
                    throw $exception;
                }

                $fallbackIdent = $this->recoverKraIdentAfterInvalidResponse($exception, $externalIdOrUrl, (string) $payload['data']['ident']);
                if (!is_string($fallbackIdent) || $fallbackIdent === '') {
                    throw $exception;
                }

                $attempt++;
                $payload['data']['ident'] = $fallbackIdent;

                if ($debug) {
                    $this->debugLog('[DOWNLOAD] Retrying with fallback ident: ' . $fallbackIdent);
                }

                continue;
            } catch (\Throwable $e) {
                if ($debug) {
                    $this->debugLog('[DOWNLOAD] API request failed: ' . $e->getMessage());
                }
                throw $e;
            }
        }
        
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            if ($debug) {
                $this->debugLog('[DOWNLOAD] No data array in response');
            }
            throw new RuntimeException('Kra.sk download response missing data for ident: ' . $ident);
        }
        
        if (!isset($data['link']) || !is_string($data['link']) || $data['link'] === '') {
            if ($debug) {
                $this->debugLog('[DOWNLOAD] No link in data, keys present: ' . implode(', ', array_keys($data)));
            }
            throw new RuntimeException('Kra.sk download link not available for ident: ' . $ident);
        }
        
        if ($debug) {
            $this->debugLog('[DOWNLOAD] Successfully got download link (length: ' . strlen($data['link']) . ')');
        }
        
        return $data['link'];
    }

    /**
     * Perform login if needed. Returns session_id string.
     */
    private function ensureSession(): string
    {
        // Re-login if session missing or older than 30 minutes.
        if ($this->sessionId !== null && $this->lastLoginTs !== null && (time() - $this->lastLoginTs) < 1800) {
            return $this->sessionId;
        }

        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');
        if ($username === '' || $password === '') {
            throw new RuntimeException('Kra.sk credentials missing');
        }

        $payload = ['data' => ['username' => $username, 'password' => $password]];
        $attempt = 0;
        $maxAttempts = 2;

        while ($attempt < $maxAttempts) {
            try {
                $response = $this->apiPost('/api/user/login', $payload, requireAuth: false, attachSession: false, mutateSession: true);
                if (!isset($response['session_id']) || !is_string($response['session_id']) || $response['session_id'] === '') {
                    throw new RuntimeException('Kra.sk login failed');
                }
                $this->sessionId = $response['session_id'];
                $this->lastLoginTs = time();
                $this->userInfoCache = null; // reset
                return $this->sessionId;
            } catch (\Throwable $exception) {
                $attempt++;
                $this->sessionId = null;
                $this->lastLoginTs = null;

                if ($attempt >= $maxAttempts || !$this->shouldRetryLogin($exception)) {
                    throw $exception;
                }

                if (($this->config['debug'] ?? false) === true) {
                    $this->debugLog('[LOGIN] Retrying after transient error: ' . $exception->getMessage());
                }
                usleep(250000);
            }
        }

        throw new RuntimeException('Kra.sk login failed');
    }

    private function shouldRetryLogin(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'invalid credentials')) {
            return true;
        }
        if (str_contains($message, 'code 1100')) {
            return true;
        }

        return false;
    }

    /**
     * Retrieve user info; ensures active subscription (subscribed_until present) else throws.
     * @return array<string,mixed>
     */
    private function userInfo(): array
    {
        if ($this->userInfoCache !== null) {
            return $this->userInfoCache;
        }
        $response = $this->apiPost('/api/user/info', [], requireAuth: true);
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new RuntimeException('Kra.sk user info missing');
        }
        if (!isset($data['subscribed_until'])) {
            throw new RuntimeException('Kra.sk subscription inactive');
        }
        $this->userInfoCache = $data;
        return $data;
    }

    /**
     * Public status for API exposure: returns days_left and subscription flag.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        try {
            $info = $this->userInfo();
            $daysLeft = isset($info['days_left']) ? (int) $info['days_left'] : null;
            return [
                'provider' => 'kraska',
                'authenticated' => $this->sessionId !== null,
                'days_left' => $daysLeft,
                'subscription_active' => isset($info['subscribed_until']),
                'subscribed_until' => $info['subscribed_until'] ?? null,
            ];
        } catch (RuntimeException $e) {
            return [
                'provider' => 'kraska',
                'authenticated' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Normalize Kra.sk file object.
     *
     * @param array<string,mixed> $file
     * @return array<string,mixed>
     */
    private function normalizeFile(array $file): array
    {
        $ident = isset($file['ident']) ? (string) $file['ident'] : '';
        $name = isset($file['name']) ? (string) $file['name'] : ($file['title'] ?? $ident);
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        $quality = $file['quality'] ?? null;
        $language = $file['language'] ?? null;
        $bitrate = $file['bitrate'] ?? null;
        $extra = [];
        $map = [
            'duration' => 'duration_seconds',
            'video_codec' => 'video_codec',
            'audio_codec' => 'audio_codec',
            'audio_channels' => 'audio_channels',
            'fps' => 'fps',
            'width' => 'width',
            'height' => 'height',
            'aspect' => 'aspect',
            'kra_ident' => 'kra_ident',
        ];
        foreach ($map as $src => $dst) {
            if (array_key_exists($src, $file) && $file[$src] !== null) {
                $extra[$dst] = $file[$src];
            }
        }

        return [
            'id' => $ident,
            'title' => $name,
            'provider' => 'kraska',
            'size_bytes' => $size,
            'size_human' => $this->formatBytes($size),
            'quality' => $quality,
            'language' => $language,
            'bitrate_kbps' => $bitrate,
            'source' => $file,
        ] + $extra;
    }

    /**
     * Normalise a Stream-Cinema menu entry for UI consumption.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function normalizeMenuEntry(array $entry): array
    {
        $typeRaw = strtolower(trim((string) ($entry['type'] ?? '')));
        $baseType = $typeRaw !== '' ? $typeRaw : (isset($entry['strms']) ? 'video' : 'dir');
        $type = $this->isPaginationType($baseType) ? 'dir' : $baseType;
        $isPagination = $this->isPaginationType($baseType);

        $result = [
            'type' => $type,
            'label' => $this->deriveTitle($entry),
            'provider' => 'kraska',
        ];

        if ($this->isDirectoryType($type)) {
            $normalizedPath = $this->extractMenuEntryPath($entry);
            if ($normalizedPath !== null) {
                $result['path'] = $normalizedPath;
            }

            $isQueueableBranch = isset($result['path']) && !$isPagination;
            $result['selectable'] = $isQueueableBranch;
            if ($isQueueableBranch) {
                $result['queue_mode'] = 'branch';
            }
        } elseif ($this->isPlayableType($type)) {
            $ident = $this->extractIdentFromMenuEntry($entry);
            if ($ident !== null) {
                $result['ident'] = $ident;
                $result['selectable'] = true;
                $result['queue_mode'] = 'single';
            } else {
                $result['selectable'] = false;
            }
        } else {
            $path = $this->extractMenuEntryPath($entry);
            if ($path !== null) {
                $result['path'] = $path;
            }

            $url = $entry['url'] ?? null;
            if (is_string($url) && str_starts_with($url, 'cmd://')) {
                $result['command'] = $url;
            }

            if (isset($result['ident'])) {
                $result['selectable'] = true;
                $result['queue_mode'] = 'single';
            } elseif (isset($result['path'])) {
                $result['selectable'] = true;
                $result['queue_mode'] = 'branch';
            } else {
                $result['selectable'] = false;
            }
        }

        $summary = $this->extractMenuPlot($entry);
        if ($summary !== null && $summary !== '') {
            $result['summary'] = $summary;
        }

        $meta = $this->extractMenuMeta($entry);
        if ($isPagination) {
            $meta['pagination'] = true;
        }
        if ($meta !== []) {
            $result['meta'] = $meta;
        }

        $art = $this->extractMenuArt($entry);
        if ($art !== []) {
            $result['art'] = $art;
        }

        return $result;
    }

    private function extractMenuEntryPath(array $entry): ?string
    {
        $candidates = [];
        if (isset($entry['path'])) {
            $candidates[] = $entry['path'];
        }
        if (isset($entry['url'])) {
            $candidates[] = $entry['url'];
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $value = trim($candidate);
            if ($value === '' || str_starts_with(strtolower($value), 'cmd://')) {
                continue;
            }

            $normalized = $this->normalizeMenuPath($value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Normalise Stream-Cinema path ensuring leading slash and preserved query string.
     */
    private function normalizeMenuPath(string $path): string
    {
        $value = trim($path);
        if ($value === '') {
            return '/';
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            $parts = parse_url($value);
            $value = $parts['path'] ?? '/';
            if (isset($parts['query']) && $parts['query'] !== '') {
                $value .= '?' . $parts['query'];
            }
        }

        if ($value === '') {
            $value = '/';
        }

        if ($value[0] !== '/') {
            $value = '/' . $value;
        }

        $segments = explode('?', $value, 2);
        $pathPart = preg_replace('#/{2,}#', '/', $segments[0]) ?: '/';
        if ($pathPart === '') {
            $pathPart = '/';
        }

        if (isset($segments[1]) && $segments[1] !== '') {
            return $pathPart . '?' . $segments[1];
        }

        return $pathPart;
    }

    /**
     * Extracts human friendly title for current menu level from response metadata.
     *
     * @param array<string,mixed> $response
     */
    private function extractMenuTitle(array $response): ?string
    {
        $system = $response['system'] ?? null;
        if (is_array($system)) {
            foreach (['setPluginCategory', 'label', 'title'] as $key) {
                if (isset($system[$key]) && is_string($system[$key]) && $system[$key] !== '') {
                    return $this->cleanMarkup($system[$key]);
                }
            }
        }

        $filter = $response['filter'] ?? null;
        if (is_array($filter) && isset($filter['label']) && is_string($filter['label']) && $filter['label'] !== '') {
            return $this->cleanMarkup($filter['label']);
        }

        return null;
    }

    /**
     * Extracts a descriptive plot/summary from menu entry if available.
     *
     * @param array<string,mixed> $entry
     */
    private function extractMenuPlot(array $entry): ?string
    {
        $preferred = strtolower((string) ($this->config['lang'] ?? 'cs'));
        $order = array_unique(array_merge([$preferred], ['cs', 'sk', 'en']));
        $i18n = $entry['i18n_info'] ?? null;
        if (is_array($i18n)) {
            foreach ($order as $lang) {
                if (!isset($i18n[$lang]) || !is_array($i18n[$lang])) {
                    continue;
                }
                $plot = $i18n[$lang]['plot'] ?? null;
                if (is_string($plot) && $plot !== '') {
                    $clean = $this->cleanMarkup($plot);
                    if ($clean !== '') {
                        return $clean;
                    }
                }
            }
        }

        foreach (['plot', 'description', 'desc'] as $field) {
            if (isset($entry[$field]) && is_string($entry[$field]) && $entry[$field] !== '') {
                $clean = $this->cleanMarkup($entry[$field]);
                if ($clean !== '') {
                    return $clean;
                }
            }
        }

        return null;
    }

    /**
     * Extract key metadata (year, rating, duration, codecs) for menu entry.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function extractMenuMeta(array $entry): array
    {
        $info = is_array($entry['info'] ?? null) ? $entry['info'] : [];
        $streamInfo = is_array($entry['stream_info'] ?? null) ? $entry['stream_info'] : [];
        $videoInfo = is_array($streamInfo['video'] ?? null) ? $streamInfo['video'] : [];
        $audioInfo = is_array($streamInfo['audio'] ?? null) ? $streamInfo['audio'] : [];

        $duration = null;
        if (isset($videoInfo['duration']) && is_numeric($videoInfo['duration'])) {
            $duration = (int) $videoInfo['duration'];
        } elseif (isset($info['duration']) && is_numeric($info['duration'])) {
            $duration = (int) $info['duration'];
        }

        $height = isset($videoInfo['height']) && is_numeric($videoInfo['height']) ? (int) $videoInfo['height'] : null;
        $width = isset($videoInfo['width']) && is_numeric($videoInfo['width']) ? (int) $videoInfo['width'] : null;
        $videoCodec = isset($videoInfo['codec']) && is_string($videoInfo['codec']) ? strtoupper($videoInfo['codec']) : null;

        $quality = null;
        if ($height !== null) {
            $quality = $height . 'p' . ($videoCodec ? ' ' . $videoCodec : '');
        } elseif ($videoCodec !== null) {
            $quality = $videoCodec;
        }

        $languages = [];
        if (isset($streamInfo['langs']) && is_array($streamInfo['langs'])) {
            $languages = array_values(array_map('strval', array_keys($streamInfo['langs'])));
        }

        $meta = [
            'year' => isset($info['year']) && is_numeric($info['year']) ? (int) $info['year'] : null,
            'rating' => isset($info['rating']) && is_numeric($info['rating']) ? (float) $info['rating'] : null,
            'season' => isset($info['season']) && is_numeric($info['season']) ? (int) $info['season'] : null,
            'episode' => isset($info['episode']) && is_numeric($info['episode']) ? (int) $info['episode'] : null,
            'duration_seconds' => $duration,
            'quality' => $quality,
            'languages' => $languages,
            'video_codec' => $videoCodec,
            'video_height' => $height,
            'video_width' => $width,
            'audio_codec' => isset($audioInfo['codec']) && is_string($audioInfo['codec']) ? strtoupper($audioInfo['codec']) : null,
            'audio_channels' => isset($audioInfo['channels']) && is_numeric($audioInfo['channels']) ? (int) $audioInfo['channels'] : null,
            'fps' => isset($streamInfo['fps']) ? (string) $streamInfo['fps'] : null,
        ];

        if ($meta['languages'] === []) {
            unset($meta['languages']);
        }

        return array_filter($meta, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Extract artwork URLs for menu entry.
     *
     * @param array<string,mixed> $entry
     * @return array<string,string>
     */
    private function extractMenuArt(array $entry): array
    {
        $art = [];
        $preferred = strtolower((string) ($this->config['lang'] ?? 'cs'));
        $order = array_unique(array_merge([$preferred], ['cs', 'sk', 'en']));
        $i18nArt = $entry['i18n_art'] ?? null;
        if (is_array($i18nArt)) {
            foreach ($order as $lang) {
                if (!isset($i18nArt[$lang]) || !is_array($i18nArt[$lang])) {
                    continue;
                }
                foreach (['thumb', 'poster', 'fanart', 'banner'] as $field) {
                    if (isset($i18nArt[$lang][$field]) && is_string($i18nArt[$lang][$field]) && $i18nArt[$lang][$field] !== '') {
                        $art[$field] = $i18nArt[$lang][$field];
                    }
                }
                if ($art !== []) {
                    break;
                }
            }
        }

        foreach (['thumb', 'poster', 'fanart', 'banner'] as $field) {
            if (!isset($art[$field]) && isset($entry[$field]) && is_string($entry[$field]) && $entry[$field] !== '') {
                $art[$field] = $entry[$field];
            }
        }

        return $art;
    }

    /**
     * Determine queue ident (Stream-Cinema path or Kra.sk ident) for playable entries.
     *
     * @param array<string,mixed> $entry
     */
    private function extractIdentFromMenuEntry(array $entry): ?string
    {
        $type = strtolower((string) ($entry['type'] ?? ''));
        if ($type !== '' && !$this->isPlayableType($type)) {
            return null;
        }

        $numericCandidate = null;
        $strms = $entry['strms'] ?? null;
        if (is_array($strms)) {
            foreach ($strms as $stream) {
                if (!is_array($stream)) {
                    continue;
                }

                $ident = $this->resolveKraStreamIdent($stream, attemptLookup: false, preferUrlOnNumeric: false);
                if (!is_string($ident) || $ident === '') {
                    continue;
                }

                if ($this->isLikelyNumericIdent($ident)) {
                    if ($numericCandidate === null) {
                        $numericCandidate = $ident;
                    }
                    continue;
                }

                return $ident;
            }
        }

        $url = $entry['url'] ?? null;
        if (is_string($url) && $url !== '') {
            $normalized = $this->normalizeMenuPath($url);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        $id = $entry['id'] ?? null;
        if (is_string($id) && $id !== '') {
            return '/Play/' . $id;
        }
        if (is_int($id) || is_float($id)) {
            return '/Play/' . (int) $id;
        }

        if ($numericCandidate !== null) {
            return $numericCandidate;
        }

        return null;
    }

    private function isDirectoryType(string $type): bool
    {
        return in_array($type, ['dir', 'directory', 'folder', 'season', 'menu', 'context'], true);
    }

    private function isPaginationType(string $type): bool
    {
        return in_array($type, ['next', 'nextpage', 'page', 'previous', 'prev'], true);
    }

    private function isPlayableType(string $type): bool
    {
        return in_array($type, ['video', 'movie', 'episode', 'file', 'stream'], true);
    }

    private function cleanMarkup(string $value): string
    {
        $stripped = preg_replace('/\[[^\]]+\]/', '', $value);
        if ($stripped === null) {
            $stripped = $value;
        }
        $text = strip_tags($stripped);
        return trim($text);
    }

    /**
     * Simple JSON GET helper for Stream-Cinema endpoints.
     * @return array<string,mixed>
     */
    private function httpGetJson(string $url, int $timeout, bool $withAuth = false, string $bucket = 'generic'): array
    {
        if ($this->scHttpGet !== null) {
            /** @var callable $cb */
            $cb = $this->scHttpGet;
            $result = $cb($url, $withAuth, $this);
            if (is_array($result)) {
                return $result;
            }
            throw new RuntimeException('Stream-Cinema test callback must return array');
        }
        $this->awaitStreamCinemaRateLimit($bucket);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to init cURL');
        }
        $headers = [
            'Accept: application/json',
            'User-Agent: ' . $this->buildUserAgent(),
        ];
        if ($withAuth) {
            $headers = array_merge($headers, $this->streamCinemaAuthHeaders());
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Stream-Cinema HTTP error: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('Stream-Cinema HTTP status ' . $code);
        }
        $decoded = json_decode($raw, true);
        // Optional debug logging: enable by setting config['debug'] = true for provider.
        if (($this->config['debug'] ?? false) === true) {
            $this->debugLog('[SC GET] ' . $url . ' => ' . substr($raw, 0, 1000));
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function awaitStreamCinemaRateLimit(string $bucket): void
    {
        if ($this->scHttpGet !== null) {
            return;
        }

        $bucket = strtolower(trim($bucket));
        if ($bucket === '') {
            $bucket = 'generic';
        }

        $enforceSpacing = $this->streamCinemaMinSpacingSeconds > 0;
        $enforceBurst = $this->streamCinemaRateLimitOptions !== [];

        if (!$enforceSpacing && !$enforceBurst) {
            return;
        }

        while (true) {
            $retryAfter = ProviderRateLimiter::acquire(
                self::RATE_LIMIT_PROVIDER_KEY,
                $bucket,
                $this->streamCinemaMinSpacingSeconds,
                [],
                $this->streamCinemaRateLimitOptions
            );
            if ($retryAfter === null) {
                return;
            }

            sleep(max(1, $retryAfter));
        }
    }

    /**
     * Build a Kodi‑like User-Agent approximating the addon format: App/Version (Platform) (lang; verAddon)
     * We lack real Kodi context, so we synthesize a stable, descriptive UA.
     */
    private function buildUserAgent(): string
    {
        $app = 'Kodi';
        $kodiVersion = '20.0';
        $platform = 'X11; U; Linux x86_64';
        $lang = 'en';
        $addonVersion = '2.0';
        return sprintf('%s/%s (%s) (%s; ver%s)', $app, $kodiVersion, $platform, $lang, $addonVersion);
    }

    /**
     * Ensure we have a valid Stream-Cinema auth token (X-AUTH-TOKEN), deriving it from the kra.sk session.
     */
    private function ensureStreamCinemaToken(): string
    {
        // Refresh every 6 hours
        if ($this->scToken !== null && $this->lastScTokenTs !== null && (time() - $this->lastScTokenTs) < 21600) {
            return $this->scToken;
        }
        $kraSession = $this->ensureSession();
        // If user supplied uuid in config keep it; else lazily generate once.
        $this->scUuid = $this->scUuid ?? (isset($this->config['uuid']) && is_string($this->config['uuid']) && $this->config['uuid'] !== ''
            ? $this->config['uuid']
            : $this->generateUuid());

        // Request token: POST /auth/token?krt=<kraSession> plus default params (ver, uid, lang, skin, HDR, DV)
        $query = $this->buildStreamCinemaParams(['krt' => $kraSession]);
        $url = self::SC_BASE . '/auth/token?' . $query;
        $headers = [
            'User-Agent: ' . $this->buildUserAgent(),
            'X-Uuid: ' . $this->scUuid,
            'Accept: application/json',
        ];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to init cURL for Stream-Cinema token');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Stream-Cinema token HTTP error: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('Stream-Cinema token HTTP status ' . $code);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['token']) || !is_string($decoded['token'])) {
            throw new RuntimeException('Stream-Cinema token missing in response');
        }
        $this->scToken = $decoded['token'];
        $this->lastScTokenTs = time();
        return $this->scToken;
    }

    /**
     * Headers required for authenticated Stream-Cinema requests.
     * @return string[]
     */
    private function streamCinemaAuthHeaders(): array
    {
        $token = $this->ensureStreamCinemaToken();
        $this->scUuid = $this->scUuid ?? (isset($this->config['uuid']) && is_string($this->config['uuid']) && $this->config['uuid'] !== ''
            ? $this->config['uuid']
            : $this->generateUuid());
        return [
            'X-AUTH-TOKEN: ' . $token,
            'X-Uuid: ' . $this->scUuid,
        ];
    }

    /**
     * Build a deterministic query string including required Stream-Cinema default params.
     * Mirrors essentials from addon (ver, uid, skin, lang, HDR, DV) while allowing overrides.
     * @param array<string,string> $extra
     */
    private function buildStreamCinemaParams(array $extra): string
    {
        $this->scUuid = $this->scUuid ?? (isset($this->config['uuid']) && is_string($this->config['uuid']) && $this->config['uuid'] !== ''
            ? $this->config['uuid']
            : $this->generateUuid());
        $defaults = [
            'ver' => '2.0',
            'uid' => $this->scUuid,
            'skin' => 'default',
            'lang' => $this->config['lang'] ?? 'en',
            'HDR' => '1',
            'DV' => '1',
        ];
        $merged = array_merge($defaults, $extra);
        ksort($merged);
        return http_build_query($merged, '', '&', PHP_QUERY_RFC3986);
    }

    private function generateUuid(): string
    {
        // Simple RFC4122 v4 UUID generator
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Fetch a Stream-Cinema item detail JSON for a given path (e.g., "/Movie/12345").
     * @return array<string,mixed>
     */
    private function fetchStreamCinemaItem(string $path): array
    {
        $rateLimit = $this->getIdentFetchRateLimitSeconds();
        $now = time();
        $canFetch = ($this->lastIdentFetchTs === null) || (($now - $this->lastIdentFetchTs) >= $rateLimit);
        if (!$canFetch) {
            // Queue for later processing; deduplicate
            if (!in_array($path, $this->scFetchQueue, true)) {
                $this->scFetchQueue[] = $path;
            }
            $retryAfter = $rateLimit;
            if ($this->lastIdentFetchTs !== null) {
                $retryAfter = max(1, ($this->lastIdentFetchTs + $rateLimit) - $now);
            }
            if (($this->config['debug'] ?? false) === true) {
                $this->debugLog(sprintf(
                    '[RATE LIMIT] Deferred ident fetch for path %s; retry in %d seconds; queue size=%d',
                    $path,
                    $retryAfter,
                    count($this->scFetchQueue)
                ));
            }
            throw new RateLimitDeferredException($retryAfter, 'Stream-Cinema ident fetch deferred due to rate limit');
        }
        // Perform real fetch
        $query = $this->buildStreamCinemaParams([]);
        $url = self::SC_BASE . $path . (str_contains($path, '?') ? '&' : '?') . $query;
        $detail = $this->httpGetJson($url, 20, true, 'meta');
        $this->lastIdentFetchTs = $now;
        return $detail;
    }

    /**
     * Call Stream-Cinema API with a stream URL (like /ws2/{token}/{file_id}) to get the actual ident.
     * This mimics the Kodi addon's behavior of calling Sc.get(stream_url) before resolving with Kra.sk.
     * @param string $streamUrl The URL from the stream's 'url' field (e.g., "/ws2/token/fileid")
     * @return array<string,mixed> The response containing 'ident' and other metadata
     */
    private function callStreamCinemaApi(string $streamUrl): array
    {
        $debug = ($this->config['debug'] ?? false) === true;
        if ($debug) {
            $this->debugLog('[SC API] Calling Stream-Cinema with stream URL: ' . $streamUrl);
        }
        
        $query = $this->buildStreamCinemaParams([]);
        $url = self::SC_BASE . $streamUrl . (str_contains($streamUrl, '?') ? '&' : '?') . $query;
        $response = $this->httpGetJson($url, 20, true, 'stream');
        
        if ($debug) {
            $this->debugLog('[SC API] Response keys: ' . implode(', ', array_keys($response)));
            if (isset($response['ident'])) {
                $this->debugLog('[SC API] Found ident: ' . $response['ident']);
            }
        }
        
        return $response;
    }

    /**
     * Attempt to extract a Kra.sk ident from a Stream-Cinema detail payload.
     * The payload typically contains 'strms' with provider-specific entries including 'ident'.
     * Returns first matching ident for provider 'kraska'.
     */
    private function extractKraIdentFromDetail(array $detail): ?string
    {
        $debug = ($this->config['debug'] ?? false) === true;
        
        $strms = $detail['strms'] ?? null;
        if (!is_array($strms) || $strms === []) {
            if ($debug) {
                $this->debugLog('[EXTRACT_IDENT] No strms array in detail');
            }
            return null;
        }

        if ($debug) {
            $this->debugLog('[EXTRACT_IDENT] Found strms array with ' . count($strms) . ' streams');
        }

        $providerSynonyms = ['kraska','kra.sk','kra','krask'];
        $numericFallback = null;

        foreach ($strms as $idx => $stream) {
            if (!is_array($stream)) {
                continue;
            }

            $provider = $stream['provider'] ?? ($stream['prov'] ?? null);
            if ($debug) {
                $this->debugLog('[EXTRACT_IDENT] Stream #' . $idx . ' provider: ' . var_export($provider, true));
            }
            if (!is_string($provider) || !in_array(strtolower($provider), $providerSynonyms, true)) {
                continue;
            }

            if ($debug) {
                $this->debugLog('[EXTRACT_IDENT] Full Kra.sk stream fields:');
                $this->debugLog('  - ident: ' . var_export($stream['ident'] ?? null, true));
                $this->debugLog('  - id: ' . var_export($stream['id'] ?? null, true));
                $this->debugLog('  - sid: ' . var_export($stream['sid'] ?? null, true));
                $this->debugLog('  - file: ' . var_export($stream['file'] ?? null, true));
                $this->debugLog('  - uuid: ' . var_export($stream['uuid'] ?? null, true));
                if (isset($stream['url'])) {
                    $this->debugLog('  - url: ' . var_export($stream['url'], true));
                }
            }

            $originalIdent = null;
            $ident = $this->resolveKraStreamIdent($stream, attemptLookup: true, preferUrlOnNumeric: false, originalIdent: $originalIdent);

            if ($debug) {
                $this->debugLog('[EXTRACT_IDENT] Resolved ident candidate: ' . var_export($ident, true));
                if ($originalIdent !== null && $originalIdent !== $ident) {
                    $this->debugLog('[EXTRACT_IDENT] Lookup converted original ident ' . var_export($originalIdent, true) . ' to ' . var_export($ident, true));
                }
            }

            if (!is_string($ident) || $ident === '') {
                continue;
            }

            if ($this->isLikelyNumericIdent($ident)) {
                if ($numericFallback === null) {
                    $numericFallback = $ident;
                }
                if ($debug) {
                    $this->debugLog('[EXTRACT_IDENT] Ident appears numeric, deferring as fallback');
                }
                continue;
            }

            if ($debug) {
                $this->debugLog('[EXTRACT_IDENT] Returning ident: ' . $ident);
            }
            return $ident;
        }

        if ($numericFallback !== null) {
            if ($debug) {
                $this->debugLog('[EXTRACT_IDENT] Falling back to numeric ident: ' . $numericFallback);
            }
            return $numericFallback;
        }

        if ($debug) {
            $this->debugLog('[EXTRACT_IDENT] No Kra.sk ident found in any stream');
        }
        return null;
    }

    /**
     * Extract a full Kra.sk stream meta entry from Stream-Cinema detail for enrichment.
     * @param array<string,mixed> $detail
     * @return array<string,mixed>|null
     */
    private function extractKraStreamMeta(array $detail): ?array
    {
        $strms = $detail['strms'] ?? null;
        if (!is_array($strms)) {
            return null;
        }
        $providerSynonyms = ['kraska','kra.sk','kra','krask'];
        foreach ($strms as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $provider = $stream['provider'] ?? ($stream['prov'] ?? null);
            if (is_string($provider) && in_array(strtolower($provider), $providerSynonyms, true)) {
                return $stream;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $detail
     * @return array<int,array<string,mixed>>
     */
    private function extractKraStreamVariants(array $detail): array
    {
        $strms = $detail['strms'] ?? null;
        if (!is_array($strms)) {
            return [];
        }

        $variants = [];
        $seen = [];
        foreach (array_values($strms) as $index => $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $variant = $this->normalizeStreamVariant($stream, $index);
            if ($variant === null) {
                continue;
            }
            $variantId = $variant['id'] ?? null;
            if (is_string($variantId) && $variantId !== '') {
                if (isset($seen[$variantId])) {
                    continue;
                }
                $seen[$variantId] = true;
            }
            $variants[] = $variant;
        }

        return $variants;
    }

    /**
     * @param array<string,mixed> $stream
     */
    private function resolveKraStreamIdent(array $stream, bool $attemptLookup, bool $preferUrlOnNumeric, ?string &$originalIdent = null): ?string
    {
        $originalIdent = null;

        $provider = $stream['provider'] ?? ($stream['prov'] ?? null);
        if (!is_string($provider) || !in_array(strtolower($provider), ['kraska', 'kra.sk', 'kra', 'krask'], true)) {
            return null;
        }

        $rawIdent = null;
        foreach (['ident', 'sid', 'uuid', 'file', 'id'] as $field) {
            if (!isset($stream[$field])) {
                continue;
            }
            $value = $stream[$field];
            if (!is_string($value)) {
                continue;
            }
            $candidate = trim($value);
            if ($candidate === '') {
                continue;
            }
            $rawIdent = $candidate;
            $originalIdent = $candidate;
            break;
        }

        $url = $this->extractStreamUrl($stream);

        if ($attemptLookup && $url !== null) {
            $needsLookup = $rawIdent === null || $rawIdent === '' || $this->isLikelyNumericIdent($rawIdent);
            if (!$needsLookup && isset($stream['sid']) && is_string($stream['sid'])) {
                $needsLookup = $rawIdent === trim($stream['sid']);
            }

            if ($needsLookup) {
                try {
                    $lookup = $this->callStreamCinemaApi($url);
                    $resolved = $lookup['ident'] ?? null;
                    if (is_string($resolved)) {
                        $resolvedTrim = trim($resolved);
                        if ($resolvedTrim !== '') {
                            return $resolvedTrim;
                        }
                    }
                } catch (\Throwable $exception) {
                    if (($this->config['debug'] ?? false) === true) {
                        $this->debugLog('[IDENT] Unable to resolve via ' . $url . ': ' . $exception->getMessage());
                    }
                }
            }
        }

        if ($rawIdent !== null && $rawIdent !== '') {
            if ($preferUrlOnNumeric && $url !== null && $this->isLikelyNumericIdent($rawIdent)) {
                return $url;
            }

            return $rawIdent;
        }

        return $url;
    }

    /**
     * @param array<string,mixed> $stream
     */
    private function extractStreamUrl(array $stream): ?string
    {
        $url = $stream['url'] ?? null;
        if (!is_string($url)) {
            return null;
        }

        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with(strtolower($trimmed), 'cmd://')) {
            return null;
        }

        $normalized = $this->normalizeMenuPath($trimmed);
        return $normalized !== '' ? $normalized : null;
    }

    private function isLikelyNumericIdent(string $value): bool
    {
        return preg_match('/^\d+$/', $value) === 1;
    }

    /**
     * @param array<string,mixed> $stream
     */
    private function normalizeStreamVariant(array $stream, int $index): ?array
    {
        $provider = $stream['provider'] ?? ($stream['prov'] ?? null);
        if (is_string($provider) && !in_array(strtolower($provider), ['kraska', 'kra.sk', 'kra', 'krask'], true)) {
            return null;
        }

        $originalIdent = null;
        $ident = $this->resolveKraStreamIdent($stream, attemptLookup: true, preferUrlOnNumeric: false, originalIdent: $originalIdent);

        if (!is_string($ident) || $ident === '') {
            return null;
        }

        if (($this->config['debug'] ?? false) === true && $originalIdent !== null && $originalIdent !== $ident) {
            $this->debugLog('[OPTIONS] Resolved stream ident from ' . $originalIdent . ' to ' . $ident);
        }

        $name = $stream['label'] ?? $stream['title'] ?? $stream['name'] ?? $stream['quality'] ?? null;
        if (!is_string($name) || $name === '') {
            $name = 'Option ' . ($index + 1);
        }

        $size = null;
        foreach (['size', 'filesize'] as $sizeKey) {
            if (isset($stream[$sizeKey]) && is_numeric($stream[$sizeKey])) {
                $size = (int) $stream[$sizeKey];
                break;
            }
        }

        $bitrate = null;
        if (isset($stream['bitrate']) && is_numeric($stream['bitrate'])) {
            $bitrate = (int) $stream['bitrate'];
        }

        $language = null;
        if (isset($stream['langs']) && is_array($stream['langs']) && $stream['langs'] !== []) {
            $language = implode(',', array_keys($stream['langs']));
        } elseif (isset($stream['lang']) && is_string($stream['lang']) && $stream['lang'] !== '') {
            $language = $stream['lang'];
        }

        $videoMeta = [];
        if (isset($stream['video']) && is_array($stream['video'])) {
            $videoMeta = $stream['video'];
        } elseif (isset($stream['stream_info']['video']) && is_array($stream['stream_info']['video'])) {
            $videoMeta = $stream['stream_info']['video'];
        }

        $audioMeta = [];
        if (isset($stream['audio']) && is_array($stream['audio'])) {
            $audioMeta = $stream['audio'];
        } elseif (isset($stream['stream_info']['audio']) && is_array($stream['stream_info']['audio'])) {
            $audioMeta = $stream['stream_info']['audio'];
        }

        $duration = null;
        if (isset($stream['duration']) && is_numeric($stream['duration'])) {
            $duration = (int) $stream['duration'];
        } elseif (isset($stream['info']['duration']) && is_numeric($stream['info']['duration'])) {
            $duration = (int) $stream['info']['duration'];
        } elseif (isset($videoMeta['duration']) && is_numeric($videoMeta['duration'])) {
            $duration = (int) $videoMeta['duration'];
        }

        $sourceEntry = $stream;
        $sourceEntry['kra_ident'] = $ident;
        if ($originalIdent !== null && $originalIdent !== $ident) {
            $sourceEntry['original_ident'] = $originalIdent;
        }

        $file = [
            'ident' => $ident,
            'name' => $name,
            'size' => $size ?? 0,
            'quality' => $stream['quality'] ?? null,
            'language' => $language,
            'bitrate' => $bitrate,
            'source_entry' => $sourceEntry,
            'kra_ident' => $ident,
        ];

        if ($duration !== null) {
            $file['duration'] = $duration;
        }

        if (isset($videoMeta['codec']) && is_string($videoMeta['codec'])) {
            $file['video_codec'] = $videoMeta['codec'];
        }
        if (isset($videoMeta['width']) && is_numeric($videoMeta['width'])) {
            $file['width'] = (int) $videoMeta['width'];
        }
        if (isset($videoMeta['height']) && is_numeric($videoMeta['height'])) {
            $file['height'] = (int) $videoMeta['height'];
            if (!isset($file['quality']) || $file['quality'] === null) {
                $codecPart = isset($videoMeta['codec']) && is_string($videoMeta['codec']) && $videoMeta['codec'] !== ''
                    ? ' ' . strtoupper($videoMeta['codec'])
                    : '';
                $file['quality'] = $videoMeta['height'] . 'p' . $codecPart;
            }
        }
        if (isset($videoMeta['fps'])) {
            $file['fps'] = $videoMeta['fps'];
        } elseif (isset($stream['fps'])) {
            $file['fps'] = $stream['fps'];
        }
        if (isset($videoMeta['aspect'])) {
            $file['aspect'] = $videoMeta['aspect'];
        } elseif (isset($videoMeta['ratio'])) {
            $file['aspect'] = $videoMeta['ratio'];
        }

        if (isset($audioMeta['codec']) && is_string($audioMeta['codec'])) {
            $file['audio_codec'] = $audioMeta['codec'];
        }
        if (isset($audioMeta['channels']) && is_numeric($audioMeta['channels'])) {
            $file['audio_channels'] = (int) $audioMeta['channels'];
        }

        if (!isset($file['kra_ident'])) {
            $file['kra_ident'] = $stream['ident'] ?? $stream['sid'] ?? null;
        }

        if (($file['size'] ?? 0) === 0 && $duration !== null && $duration > 0 && $bitrate !== null && $bitrate > 0) {
            $file['size'] = (int) (($bitrate * $duration * 1000) / 8);
        }

        return $this->normalizeFile($file);
    }

    /**
     * @param array<string,mixed>|null $detail
     * @return array<string,mixed>
     */
    private function buildFallbackVariant(string $ident, ?array $detail): array
    {
        $title = null;
        if (is_array($detail)) {
            if (isset($detail['label']) && is_string($detail['label']) && $detail['label'] !== '') {
                $title = $this->cleanMarkup((string) $detail['label']);
            } elseif (isset($detail['title']) && is_string($detail['title']) && $detail['title'] !== '') {
                $title = $this->cleanMarkup((string) $detail['title']);
            }
        }

        if ($title === null || $title === '') {
            $title = 'Download ' . $ident;
        }

        $file = [
            'ident' => $ident,
            'name' => $title,
            'size' => 0,
            'quality' => null,
            'language' => null,
            'bitrate' => null,
            'source_entry' => [
                'fallback' => true,
                'ident' => $ident,
                'detail' => $detail,
            ],
        ];

        $file['kra_ident'] = $ident;

        return $this->normalizeFile($file);
    }

    private function recoverKraIdentAfterInvalidResponse(KraSkApiException $exception, string $originalValue, string $currentIdent): ?string
    {
        if ($exception->getStatusCode() !== 400) {
            return null;
        }

        $responseBody = $exception->getResponseBody();
        $shouldRetry = false;
        if (is_string($responseBody) && $responseBody !== '') {
            $decoded = json_decode($responseBody, true);
            if (is_array($decoded)) {
                $errorCode = $decoded['error'] ?? null;
                if (is_numeric($errorCode) && (int) $errorCode === 1207) {
                    $shouldRetry = true;
                }
                $message = $decoded['msg'] ?? ($decoded['message'] ?? null);
                if (!$shouldRetry && is_string($message) && stripos($message, 'invalid ident') !== false) {
                    $shouldRetry = true;
                }
            } elseif (stripos($responseBody, 'invalid ident') !== false) {
                $shouldRetry = true;
            }
        }

        if (!$shouldRetry) {
            return null;
        }

        return $this->deriveIdentFromStreamCinema($originalValue, $currentIdent);
    }

    private function deriveIdentFromStreamCinema(string $originalValue, string $currentIdent): ?string
    {
        $candidates = [];

        if (preg_match('#^/Play/\d+$#i', $originalValue) === 1) {
            $candidates[] = $this->normalizeMenuPath($originalValue);
        } elseif (preg_match('#^\d+$#', $originalValue) === 1) {
            $candidates[] = '/Play/' . $originalValue;
        } elseif (preg_match('#^https?://#i', $originalValue) === 1) {
            $normalized = $this->normalizeMenuPath($originalValue);
            if ($normalized !== '') {
                $candidates[] = $normalized;
            }
        }

        if (preg_match('#^\d+$#', $currentIdent) === 1) {
            $fallbackPath = '/Play/' . $currentIdent;
            if (!in_array($fallbackPath, $candidates, true)) {
                $candidates[] = $fallbackPath;
            }
        }

        $candidates = array_values(array_unique($candidates));
        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $path) {
            try {
                $detail = $this->fetchStreamCinemaItem($path);
            } catch (RateLimitDeferredException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                if (($this->config['debug'] ?? false) === true) {
                    $this->debugLog('[FALLBACK] Failed to fetch detail for ' . $path . ': ' . $exception->getMessage());
                }
                continue;
            }

            $ident = $this->extractKraIdentFromDetail($detail);
            if (is_string($ident) && $ident !== '') {
                if (($this->config['debug'] ?? false) === true) {
                    $this->debugLog('[FALLBACK] extractKraIdentFromDetail returned ' . $ident . ' for ' . $path);
                }
                return $ident;
            }

            $strms = $detail['strms'] ?? null;
            if (!is_array($strms)) {
                continue;
            }

            foreach ($strms as $stream) {
                if (!is_array($stream)) {
                    continue;
                }

                $provider = $stream['provider'] ?? ($stream['prov'] ?? null);
                if (!is_string($provider) || !in_array(strtolower($provider), ['kraska', 'kra.sk', 'kra', 'krask'], true)) {
                    continue;
                }

                $directIdent = $stream['ident'] ?? ($stream['sid'] ?? null);
                if (is_string($directIdent) && $directIdent !== '') {
                    if (($this->config['debug'] ?? false) === true) {
                        $this->debugLog('[FALLBACK] Using stream ident ' . $directIdent . ' from ' . $path);
                    }
                    return $directIdent;
                }

                if (isset($stream['url']) && is_string($stream['url']) && $stream['url'] !== '') {
                    try {
                        $lookup = $this->callStreamCinemaApi($stream['url']);
                        $resolved = $lookup['ident'] ?? null;
                        if (is_string($resolved) && $resolved !== '') {
                            if (($this->config['debug'] ?? false) === true) {
                                $this->debugLog('[FALLBACK] Resolved ident ' . $resolved . ' via stream URL ' . $stream['url']);
                            }
                            return $resolved;
                        }
                    } catch (\Throwable $exception) {
                        if (($this->config['debug'] ?? false) === true) {
                            $this->debugLog('[FALLBACK] Failed to resolve via ' . $stream['url'] . ': ' . $exception->getMessage());
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Derive a human friendly title from a Stream-Cinema search entry.
     * Strips BBCode-like tags (e.g. [B]..[/B]).
     */
    private function deriveTitle(array $entry): string
    {
        // Try i18n_info first.
        $preferred = strtolower((string) ($this->config['lang'] ?? 'cs'));
        $i18n = $entry['i18n_info'] ?? null;
        $candidates = [];
        if (is_array($i18n)) {
            if (isset($i18n[$preferred]) && is_array($i18n[$preferred]) && isset($i18n[$preferred]['title'])) {
                $candidates[] = $i18n[$preferred]['title'];
            }
            // Fallback order
            foreach (['cs','sk','en'] as $lang) {
                if ($lang === $preferred) continue;
                if (isset($i18n[$lang]) && is_array($i18n[$lang]) && isset($i18n[$lang]['title'])) {
                    $candidates[] = $i18n[$lang]['title'];
                }
            }
        }
        // Additional fallbacks: top-level title / name.
        if (isset($entry['title'])) { $candidates[] = $entry['title']; }
        if (isset($entry['name'])) { $candidates[] = $entry['name']; }
        // Last resort: unique_ids.sc or id
        if (isset($entry['unique_ids']['sc'])) { $candidates[] = $entry['unique_ids']['sc']; }
        if (isset($entry['id'])) { $candidates[] = (string) $entry['id']; }

        foreach ($candidates as $raw) {
            if (!is_string($raw) || $raw === '') continue;
            // Remove BBCode-like tags and trim
            $clean = trim(preg_replace('/\[[^\]]+\]/', '', $raw));
            if ($clean !== '') {
                return $clean;
            }
        }
        return 'unknown';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B','KB','MB','GB','TB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        $value = $bytes / (1024 ** $power);
        return sprintf('%.2f %s', $value, $units[$power]);
    }

    /**
     * Low-level API POST.
     * Kra.sk expects JSON body { ... } and returns JSON. If a session is available and required we append session_id at top-level (same as plugin logic adding to payload root).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function apiPost(string $endpoint, array $payload, bool $requireAuth, bool $attachSession = true, bool $mutateSession = false, int $attempt = 0): array
    {
        if ($requireAuth && $this->sessionId === null) {
            // Establish session first (will invoke adapter for login if provided)
            $this->ensureSession();
        }
        if ($this->httpAdapter !== null) {
            /** @var callable $adapter */
            $adapter = $this->httpAdapter;
            /** @var array<string,mixed> $mock */
            $mock = $adapter($endpoint, $payload, $requireAuth, $attachSession, $mutateSession, $this);
            if ($mutateSession && isset($mock['session_id']) && is_string($mock['session_id'])) {
                $this->sessionId = $mock['session_id'];
                $this->lastLoginTs = time();
            }
            return $mock;
        }
        if ($requireAuth) {
            $this->ensureSession();
        }

        $payloadToSend = $payload;
        if ($attachSession && $this->sessionId !== null) {
            // In Kodi plugin they add session_id alongside provided payload keys.
            $payloadToSend['session_id'] = $this->sessionId;
        }

        $url = self::API_BASE . $endpoint;
        $body = json_encode($payloadToSend, JSON_THROW_ON_ERROR);
        
        $debug = ($this->config['debug'] ?? false) === true;
        if ($debug) {
            $this->debugLog('[API POST] Endpoint: ' . $endpoint);
            $this->debugLog('[API POST] Request body: ' . $body);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for Kra.sk');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                // Use the same synthesized Kodi-like User-Agent as Stream-Cinema calls for parity with the
                // original addon rather than a custom identifier to reduce fingerprint divergence.
                'User-Agent: ' . $this->buildUserAgent(),
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Kra.sk HTTP error: ' . $err);
        }
        if ($code === 401 && $requireAuth && $attempt < 1) {
            if ($debug) {
                $this->debugLog('[API POST] Received 401 for ' . $endpoint . ', attempting session refresh');
            }
            $this->sessionId = null;
            try {
                $this->ensureSession();
            } catch (\Throwable $loginError) {
                // If re-login fails, fall through to exception handling below.
                if ($debug) {
                    $this->debugLog('[API POST] Session refresh failed: ' . $loginError->getMessage());
                }
            }

            return $this->apiPost($endpoint, $payload, $requireAuth, $attachSession, $mutateSession, $attempt + 1);
        }

        if ($code < 200 || $code >= 300) {
            $responseSnippet = is_string($raw) ? substr($raw, 0, 1000) : null;
            throw new KraSkApiException(
                $code,
                $endpoint,
                $this->sanitizePayloadForLogging($payloadToSend),
                $url,
                $responseSnippet
            );
        }

        try {
            /** @var array<string,mixed>|null $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Kra.sk JSON decode error: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Kra.sk empty response');
        }
        if (($this->config['debug'] ?? false) === true) {
            $this->debugLog('[KRA POST] ' . $endpoint . ' => ' . substr(json_encode($decoded), 0, 1000));
        }

        // If login succeeded, capture session id.
        if ($mutateSession && isset($decoded['session_id']) && is_string($decoded['session_id'])) {
            $this->sessionId = $decoded['session_id'];
            $this->lastLoginTs = time();
        }

        // Kra.sk often encodes errors inside 'error' or 'msg'
        if (isset($decoded['error'])) {
            if ($requireAuth) {
                $this->sessionId = null;
            }

            $errorCode = $decoded['error'];
            $message = null;

            if (isset($decoded['msg']) && is_string($decoded['msg']) && $decoded['msg'] !== '') {
                $message = $decoded['msg'];
            } elseif (isset($decoded['message']) && is_string($decoded['message']) && $decoded['message'] !== '') {
                $message = $decoded['message'];
            }

            if (is_numeric($errorCode)) {
                $errorCode = (int) $errorCode;
            }

            $shouldRetryAuth = false;

            if (is_numeric($errorCode) && (int) $errorCode === 401) {
                $shouldRetryAuth = true;
            } elseif (is_string($errorCode) && stripos($errorCode, 'auth') !== false) {
                $shouldRetryAuth = true;
            } elseif (is_string($message) && stripos($message, 'auth') !== false) {
                $shouldRetryAuth = true;
            }

            if ($requireAuth && $shouldRetryAuth && $attempt < 1) {
                if ($debug) {
                    $this->debugLog('[API POST] Kra.sk reported authorization error for ' . $endpoint . ', retrying after login');
                }
                try {
                    $this->ensureSession();
                } catch (\Throwable $loginError) {
                    if ($debug) {
                        $this->debugLog('[API POST] Re-login failed after error response: ' . $loginError->getMessage());
                    }
                }

                return $this->apiPost($endpoint, $payload, $requireAuth, $attachSession, $mutateSession, $attempt + 1);
            }

            $messageParts = [];
            if (is_numeric($errorCode)) {
                $messageParts[] = 'Code ' . $errorCode;
            }
            if ($message !== null) {
                $messageParts[] = $message;
            }

            $errorDescription = $messageParts !== []
                ? implode(': ', $messageParts)
                : (is_string($errorCode) ? $errorCode : 'unknown');

            throw new RuntimeException('Kra.sk API error: ' . $errorDescription);
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function sanitizePayloadForLogging(array $payload): array
    {
        $sensitiveKeys = ['password', 'session_id', 'token', 'authorization', 'auth_token'];
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayloadForLogging($value);
                continue;
            }

            if (is_string($key) && in_array($key, $sensitiveKeys, true) && (is_scalar($value) || $value === null)) {
                $sanitized[$key] = '***';
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function debugLog(string $line): void
    {
        // Check if debug logging is enabled (configurable via settings or config)
        if (($this->config['debug'] ?? false) !== true) {
            return;
        }

        // app/Providers -> app -> project root (2 levels up)
        $root = dirname(__DIR__, 2);
        $file = $root . '/storage/logs/kraska_debug.log';
        
        // Censor sensitive data before logging
        $sanitized = $this->censorSensitiveData($line);
        
        $ts = date('c');
        
        // Rotate log if it gets too large (before appending)
        if (file_exists($file)) {
            clearstatcache(true, $file);
            $size = @filesize($file);
            if ($size !== false && $size > 10485760) { // 10MB
                // Simple rotation: keep up to 5 rotations
                $this->rotateDebugLog($file);
            }
        }
        
        @file_put_contents($file, '[' . $ts . '] ' . $sanitized . "\n", FILE_APPEND);
    }

    /**
     * Censors sensitive data from log messages.
     */
    private function censorSensitiveData(string $message): string
    {
        // Censor JSON payloads containing credentials
        $censored = preg_replace_callback(
            '/"(password|session_id|api_key|token|secret|auth)"\s*:\s*"([^"]+)"/i',
            function ($matches) {
                $key = $matches[1];
                $value = $matches[2];
                $masked = strlen($value) > 4 
                    ? substr($value, 0, 2) . str_repeat('*', min(8, strlen($value) - 4)) . substr($value, -2)
                    : str_repeat('*', strlen($value));
                return '"' . $key . '":"' . $masked . '"';
            },
            $message
        );
        
        // Censor query parameters in URLs
        $censored = preg_replace_callback(
            '/([?&])(password|token|api_key|secret|auth|session_id)=([^&\s]+)/i',
            function ($matches) {
                $separator = $matches[1];
                $key = $matches[2];
                $value = $matches[3];
                $masked = strlen($value) > 4
                    ? substr($value, 0, 2) . str_repeat('*', min(8, strlen($value) - 4)) . substr($value, -2)
                    : str_repeat('*', strlen($value));
                return $separator . $key . '=' . $masked;
            },
            $censored
        );
        
        return $censored;
    }

    /**
     * Rotates the debug log file, keeping only 5 rotations.
     */
    private function rotateDebugLog(string $logPath): void
    {
        $maxRotations = 5;
        
        // Remove the oldest rotation
        $oldestRotation = $logPath . '.' . $maxRotations;
        if (file_exists($oldestRotation)) {
            @unlink($oldestRotation);
        }
        
        // Shift existing rotations
        for ($i = $maxRotations - 1; $i >= 1; $i--) {
            $oldFile = $logPath . '.' . $i;
            $newFile = $logPath . '.' . ($i + 1);
            if (file_exists($oldFile)) {
                @rename($oldFile, $newFile);
            }
        }
        
        // Rotate the current log file
        @rename($logPath, $logPath . '.1');
    }

    /**
     * Number of seconds between allowed Stream-Cinema detail ident fetches.
     */
    private function getIdentFetchRateLimitSeconds(): int
    {
        $raw = $this->config['ident_rate_limit_seconds'] ?? $this->config['sc_ident_rate_limit_seconds'] ?? null;

        if ($raw === null) {
            $env = getenv('KRA_SC_IDENT_RATE_LIMIT_SECONDS');
            if (is_string($env) && $env !== '') {
                $raw = $env;
            }
        }

        if (is_string($raw)) {
            $raw = trim($raw);
        }

        if ($raw === null || $raw === '') {
            return 120;
        }

        if (!is_int($raw)) {
            if (is_numeric((string) $raw)) {
                $raw = (int) $raw;
            } else {
                return 120;
            }
        }

        $val = (int) $raw;

        if ($val <= 0) {
            return 0;
        }

        return $val;
    }

    /**
     * Process queued ident fetch paths respecting rate limit. Will attempt up to $max items; if rate limit
     * blocks the first item it aborts early. Returns number of successfully fetched (and removed) entries.
     */
    public function processIdentFetchQueue(int $max = 1): int
    {
        $processed = 0;
        $rateLimit = $this->getIdentFetchRateLimitSeconds();
        $now = time();
        while ($processed < $max && !empty($this->scFetchQueue)) {
            $canFetch = ($this->lastIdentFetchTs === null) || (($now - $this->lastIdentFetchTs) >= $rateLimit);
            if (!$canFetch) { break; }
            $path = array_shift($this->scFetchQueue);
            try {
                $query = $this->buildStreamCinemaParams([]);
                $url = self::SC_BASE . $path . (str_contains($path, '?') ? '&' : '?') . $query;
                $detail = $this->httpGetJson($url, 20, true, 'meta');
                $this->lastIdentFetchTs = time();
                $processed++;
                // For now we only fetch & drop; enrichment will occur on subsequent operations when needed.
                if (($this->config['debug'] ?? false) === true) {
                    $this->debugLog('[RATE LIMIT QUEUE] Fetched queued path ' . $path . '; processed=' . $processed);
                }
            } catch (\Throwable $e) {
                // On error requeue once (simple retry strategy) then abort loop
                if (!in_array($path, $this->scFetchQueue, true)) {
                    $this->scFetchQueue[] = $path; // put back for later
                }
                if (($this->config['debug'] ?? false) === true) {
                    $this->debugLog('[RATE LIMIT QUEUE] Error fetching ' . $path . ' : ' . $e->getMessage());
                }
                break;
            }
        }
        return $processed;
    }

    /**
     * Return current queued paths waiting for ident fetch.
     * @return array<int,string>
     */
    public function getQueuedIdentFetchPaths(): array
    {
        return $this->scFetchQueue;
    }
}
