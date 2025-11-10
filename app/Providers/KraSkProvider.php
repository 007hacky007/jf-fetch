<?php

declare(strict_types=1);

namespace App\Providers;

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
                $data = $this->httpGetJson($url . '?' . $params, 15, true);
                $part = is_array($data['menu'] ?? null) ? $data['menu'] : [];
                if (!empty($part)) { $aggregated = array_merge($aggregated, $part); }
                if (count($aggregated) >= $limit) { break; }
            }
            if (count($aggregated) < $limit && strlen($query) > 2) {
                $url = self::SC_BASE . '/Search/' . $peopleCategory;
                $params = $this->buildStreamCinemaParams(['search' => $query, 'id' => $peopleCategory, 'ms' => '1']);
                $data = $this->httpGetJson($url . '?' . $params, 15, true);
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

        if ($debug) {
            $this->debugLog('[DOWNLOAD] Final ident before API call: ' . $ident);
        }

        $payload = [ 'data' => [ 'ident' => $ident ] ];
        if ($debug) {
            $this->debugLog('[DOWNLOAD] Payload before apiPost: ' . json_encode($payload));
        }
        
        try {
            $response = $this->apiPost('/api/file/download', $payload, requireAuth: true);
            if ($debug) {
                $this->debugLog('[DOWNLOAD] API response keys: ' . implode(', ', array_keys($response)));
            }
        } catch (\Throwable $e) {
            if ($debug) {
                $this->debugLog('[DOWNLOAD] API request failed: ' . $e->getMessage());
            }
            throw $e;
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
        $response = $this->apiPost('/api/user/login', $payload, requireAuth: false, attachSession: false, mutateSession: true);
        if (!isset($response['session_id']) || !is_string($response['session_id']) || $response['session_id'] === '') {
            throw new RuntimeException('Kra.sk login failed');
        }
        $this->sessionId = $response['session_id'];
        $this->lastLoginTs = time();
        $this->userInfoCache = null; // reset
        return $this->sessionId;
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
     * Simple JSON GET helper for Stream-Cinema endpoints.
     * @return array<string,mixed>
     */
    private function httpGetJson(string $url, int $timeout, bool $withAuth = false): array
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
        $detail = $this->httpGetJson($url, 20, true);
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
        $response = $this->httpGetJson($url, 20, true);
        
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
        if (!is_array($strms)) {
            if ($debug) {
                $this->debugLog('[EXTRACT_IDENT] No strms array in detail');
            }
            return null;
        }
        
        if ($debug) {
            $this->debugLog('[EXTRACT_IDENT] Found strms array with ' . count($strms) . ' streams');
        }
        
        $providerSynonyms = ['kraska','kra.sk','kra','krask'];
        foreach ($strms as $idx => $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $provider = $stream['provider'] ?? ($stream['prov'] ?? null);
            if ($debug) {
                $this->debugLog('[EXTRACT_IDENT] Stream #' . $idx . ' provider: ' . var_export($provider, true));
            }
            if (is_string($provider) && in_array(strtolower($provider), $providerSynonyms, true)) {
                // Log all relevant fields to debug which one has the correct Kra.sk file identifier
                if ($debug) {
                    $this->debugLog('[EXTRACT_IDENT] Full Kra.sk stream fields:');
                    $this->debugLog('  - ident: ' . var_export($stream['ident'] ?? null, true));
                    $this->debugLog('  - id: ' . var_export($stream['id'] ?? null, true));
                    $this->debugLog('  - sid: ' . var_export($stream['sid'] ?? null, true));
                    $this->debugLog('  - file: ' . var_export($stream['file'] ?? null, true));
                    $this->debugLog('  - uuid: ' . var_export($stream['uuid'] ?? null, true));
                }
                
                // Try multiple fields in priority order: ident, sid, uuid, file, id
                $ident = $stream['ident'] ?? ($stream['sid'] ?? ($stream['uuid'] ?? ($stream['file'] ?? ($stream['id'] ?? null))));
                if ($debug) {
                    $this->debugLog('[EXTRACT_IDENT] Selected ident value: ' . var_export($ident, true));
                }
                if (is_string($ident) && $ident !== '') {
                    if ($debug) {
                        $this->debugLog('[EXTRACT_IDENT] Returning ident: ' . $ident);
                    }
                    return $ident;
                }
            }
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
    private function apiPost(string $endpoint, array $payload, bool $requireAuth, bool $attachSession = true, bool $mutateSession = false): array
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
        if ($attachSession && $this->sessionId !== null) {
            // In Kodi plugin they add session_id alongside provided payload keys.
            $payload['session_id'] = $this->sessionId;
        }

        $url = self::API_BASE . $endpoint;
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        
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
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('Kra.sk API HTTP status ' . $code);
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
            // if auth error, reset session
            if ($requireAuth) {
                $this->sessionId = null;
            }
            throw new RuntimeException('Kra.sk API error: ' . (is_string($decoded['error']) ? $decoded['error'] : 'unknown'));
        }

        return $decoded;
    }

    private function debugLog(string $line): void
    {
        // app/Providers -> app -> project root (2 levels up)
        $root = dirname(__DIR__, 2);
        $file = $root . '/storage/logs/kraska_debug.log';
        $ts = date('c');
        @file_put_contents($file, '[' . $ts . '] ' . $line . "\n", FILE_APPEND);
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

        return $val > 0 ? $val : 120;
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
                $detail = $this->httpGetJson($url, 20, true);
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

// Custom exception for deferred ident fetches due to rate limiting.
class RateLimitDeferredException extends RuntimeException
{
    private int $retryAfterSeconds;

    public function __construct(int $retryAfterSeconds, string $message = 'Stream-Cinema ident fetch deferred due to rate limit', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->retryAfterSeconds = max(1, $retryAfterSeconds);
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
