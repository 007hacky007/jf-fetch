<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infra\Config;
use JsonException;
use RuntimeException;

/**
 * Webshare implementation of the VideoProvider interface.
 *
 * Communicates with the Webshare API using the WST token stored in configuration.
 * Normalizes search results to a consistent schema expected by the rest of the app.
 */
final class WebshareProvider implements VideoProvider, StatusCapableProvider
{
    private const API_BASE = 'https://webshare.cz/api/';

    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, array<string, mixed>> */
    private array $fileInfoCache = [];

    /**
     * @param array<string, mixed> $config Optional provider-specific configuration.
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Performs a search against Webshare.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 50): array
    {
        $payload = [
            'what' => $query,
            'limit' => $limit,
            'offset' => 0,
            'sort' => 'recent',
            'category' => 'video',
        ];

        $response = $this->post('search/', $payload);

        $files = $response['files'] ?? ($response['file'] ?? null);
        if (!is_array($files)) {
            return [];
        }

        if (!array_is_list($files) && isset($files['ident'])) {
            $files = [$files];
        }

        $results = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $results[] = $this->normalizeFile($file);
        }

        return $results;
    }

    /**
     * Resolves the download URL for a Webshare file.
     *
     * @return string
     */
    public function resolveDownloadUrl(string $externalIdOrUrl): string|array
    {
        // If the input already looks like a full URL, return it directly.
        if (str_starts_with($externalIdOrUrl, 'http://') || str_starts_with($externalIdOrUrl, 'https://')) {
            return $externalIdOrUrl;
        }

        $payload = ['ident' => $externalIdOrUrl];
        $response = $this->post('file_link/', $payload);

        if (!isset($response['link']) || !is_string($response['link']) || $response['link'] === '') {
            throw new RuntimeException('Webshare download link not available.');
        }

        return $response['link'];
    }

    /**
     * Normalizes Webshare file metadata to the app schema.
     *
     * @param array<string, mixed> $file
     *
     * @return array<string, mixed>
     */
    private function normalizeFile(array $file): array
    {
        $sizeBytes = isset($file['size']) ? (int) $file['size'] : 0;
        $durationSeconds = isset($file['duration']) ? (int) $file['duration'] : null;

        $ident = isset($file['ident']) ? (string) $file['ident'] : '';
        $info = null;
        if ($ident !== '') {
            try {
                $info = $this->getFileInfo($ident);
            } catch (RuntimeException) {
                $info = null;
            }
        }

        if ($info !== null) {
            $durationSeconds = $durationSeconds ?? (isset($info['length']) ? (int) $info['length'] : null);
            $sizeBytes = $sizeBytes > 0 ? $sizeBytes : (isset($info['size']) ? (int) $info['size'] : 0);
        }

        $videoDetails = $this->extractVideoDetails($info);
        $audioDetails = $this->extractAudioDetails($info);

        $bitrateFromFile = isset($file['bitrate']) ? (int) $file['bitrate'] : (isset($file['video_bitrate']) ? (int) $file['video_bitrate'] : null);
        $bitrateFromInfo = isset($info['bitrate']) ? (int) $info['bitrate'] : ($videoDetails['bitrate_bps'] ?? null);
        $bitrateBps = $bitrateFromFile ?? $bitrateFromInfo;

        return [
            'id' => (string) ($file['ident'] ?? ''),
            'title' => (string) ($file['name'] ?? ''),
            'provider' => 'webshare',
            'size_bytes' => $sizeBytes,
            'size_human' => $this->formatBytes($sizeBytes),
            'duration_seconds' => $durationSeconds,
            'thumbnail' => $file['image'] ?? ($file['icon'] ?? ($file['img'] ?? ($info['stripe'] ?? null))),
            'resolution' => $file['resolution'] ?? $file['video_resolution'] ?? $videoDetails['resolution'] ?? null,
            'video_codec' => $file['video_codec'] ?? ($videoDetails['codec'] ?? null),
            'audio_codec' => $file['audio_codec'] ?? ($audioDetails['codec'] ?? null),
            'bitrate_kbps' => $bitrateBps !== null ? (int) round($bitrateBps / 1000) : null,
            'video_width' => $videoDetails['width'] ?? null,
            'video_height' => $videoDetails['height'] ?? null,
            'video_fps' => $videoDetails['fps'] ?? null,
            'audio_channels' => $audioDetails['channels'] ?? null,
            'audio_language' => $audioDetails['language'] ?? null,
            'source' => $file,
        ];
    }

    /**
     * Formats bytes into a human-readable string.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        $value = $bytes / (1024 ** $power);

        return sprintf('%.2f %s', $value, $units[$power]);
    }

    /**
     * Executes a POST request to the Webshare API.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $token = $this->resolveToken();
        if ($token === null || $token === '') {
            throw new RuntimeException('Webshare WST token missing from configuration.');
        }

        $payload['wst'] = $token;

        return self::sendRequest($path, $payload);
    }

    public static function fetchToken(string $username, string $password): string
    {
        $payload = [
            'username_or_email' => $username,
            'password' => self::hashPassword($username, $password),
            'keep_logged_in' => 1,
        ];

        $response = self::sendRequest('login/', $payload, false);

        if (isset($response['data']) && is_array($response['data'])) {
            $response = $response['data'];
        }

        if (isset($response['status']) && is_string($response['status']) && strtolower($response['status']) !== 'success' && strtolower($response['status']) !== 'ok') {
            $message = isset($response['message']) && is_string($response['message']) ? $response['message'] : 'Webshare login failed.';
            throw new RuntimeException($message);
        }

        $token = $response['token'] ?? ($response['wst'] ?? null);
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Webshare login did not return a token.');
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function sendRequest(string $path, array $payload, bool $includeToken = true): array
    {
        $url = self::API_BASE . ltrim($path, '/');

        if ($includeToken && !isset($payload['wst'])) {
            throw new RuntimeException('Missing Webshare authentication token.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for Webshare.');
        }

        $encoded = http_build_query($payload);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'Accept: application/json, text/json, text/xml; charset=UTF-8, */*;q=0.8',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Webshare API request failed: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Webshare API returned HTTP status ' . $status);
        }

        return self::decodeResponse($response, $contentType);
    }

    /**
     * Resolves the Webshare token from provider config or global configuration.
     */
    private function resolveToken(): ?string
    {
        if (isset($this->config['wst']) && is_string($this->config['wst']) && $this->config['wst'] !== '') {
            return $this->config['wst'];
        }

        if (isset($this->config['token']) && is_string($this->config['token']) && $this->config['token'] !== '') {
            return $this->config['token'];
        }

        $username = isset($this->config['username']) ? trim((string) $this->config['username']) : '';
        $password = isset($this->config['password']) ? (string) $this->config['password'] : '';

        if ($username !== '' && $password !== '') {
            $token = self::fetchToken($username, $password);
            $this->config['wst'] = $token;

            return $token;
        }

        $token = Config::get('webshare.wst');

        return is_string($token) ? $token : null;
    }

    private static function hashPassword(string $username, string $password): string
    {
        $saltResponse = self::sendRequest('salt/', ['username_or_email' => $username], false);
        if (isset($saltResponse['data']) && is_array($saltResponse['data'])) {
            $saltResponse = $saltResponse['data'];
        }

        if (isset($saltResponse['status']) && is_string($saltResponse['status']) && strtolower($saltResponse['status']) !== 'success' && strtolower($saltResponse['status']) !== 'ok') {
            $message = isset($saltResponse['message']) && is_string($saltResponse['message']) ? $saltResponse['message'] : 'Webshare salt request failed.';
            throw new RuntimeException($message);
        }

        $salt = $saltResponse['salt'] ?? null;
        if (!is_string($salt) || $salt === '') {
            throw new RuntimeException('Webshare salt request did not return a salt.');
        }

        $md5Crypt = self::md5Crypt($password, $salt);
        $hashed = sha1($md5Crypt);

        if (!is_string($hashed) || $hashed === '') {
            throw new RuntimeException('Failed to derive Webshare password digest.');
        }

        return strtolower($hashed);
    }

    private static function md5Crypt(string $password, string $salt): string
    {
        $salt = substr($salt, 0, 8);
        $hash = crypt($password, '$1$' . $salt . '$');

        if (!is_string($hash) || $hash === '' || str_starts_with($hash, '*')) {
            throw new RuntimeException('Failed to apply MD5-CRYPT to Webshare password.');
        }

        return $hash;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeResponse(string $body, string $contentType): array
    {
        $contentType = strtolower($contentType);

        if (str_contains($contentType, 'json')) {
            return self::decodeJson($body);
        }

        if (str_contains($contentType, 'xml')) {
            return self::decodeXml($body);
        }

        try {
            return self::decodeJson($body);
        } catch (RuntimeException) {
            return self::decodeXml($body);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJson(string $body): array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to parse Webshare JSON response: ' . $exception->getMessage(), 0, $exception);
        }

        if (isset($decoded['response']) && is_array($decoded['response'])) {
            $decoded = $decoded['response'];
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeXml(string $body): array
    {
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            throw new RuntimeException('Failed to parse Webshare XML response.');
        }

        $json = json_encode($xml);
        if ($json === false) {
            throw new RuntimeException('Failed to normalize Webshare XML response.');
        }

        /** @var array<string, mixed>|false $decoded */
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Failed to decode Webshare XML response.');
        }

        if (isset($decoded['response']) && is_array($decoded['response'])) {
            $decoded = $decoded['response'];
        }

        return $decoded;
    }

    private function getFileInfo(string $ident): ?array
    {
        if (isset($this->fileInfoCache[$ident])) {
            return $this->fileInfoCache[$ident];
        }

        try {
            $response = $this->post('file_info/', ['ident' => $ident]);
        } catch (RuntimeException $exception) {
            throw new RuntimeException('Webshare file info request failed: ' . $exception->getMessage(), 0, $exception);
        }

        if (isset($response['status']) && is_string($response['status']) && strtolower($response['status']) !== 'success' && strtolower($response['status']) !== 'ok') {
            $message = isset($response['message']) && is_string($response['message']) ? $response['message'] : 'Webshare file info request unsuccessful.';
            throw new RuntimeException($message);
        }

        $this->fileInfoCache[$ident] = $response;

        return $this->fileInfoCache[$ident];
    }

    /**
     * @param array<string, mixed>|null $info
     *
     * @return array<string, int|float|string|null>
     */
    private function extractVideoDetails(?array $info): array
    {
        if ($info === null) {
            return [];
        }

        $video = $info['video']['stream'] ?? null;
        if ($video === null) {
            return [
                'width' => isset($info['width']) ? (int) $info['width'] : null,
                'height' => isset($info['height']) ? (int) $info['height'] : null,
                'fps' => isset($info['fps']) ? (float) $info['fps'] : null,
                'codec' => $info['format'] ?? null,
                'bitrate_bps' => isset($info['bitrate']) ? (int) $info['bitrate'] : null,
                'resolution' => (isset($info['width'], $info['height'])) ? sprintf('%sx%s', $info['width'], $info['height']) : null,
            ];
        }

        if (isset($video[0]) && is_array($video[0])) {
            $video = $video[0];
        }

        return [
            'width' => isset($video['width']) ? (int) $video['width'] : (isset($info['width']) ? (int) $info['width'] : null),
            'height' => isset($video['height']) ? (int) $video['height'] : (isset($info['height']) ? (int) $info['height'] : null),
            'fps' => isset($video['fps']) ? (float) $video['fps'] : (isset($info['fps']) ? (float) $info['fps'] : null),
            'codec' => $video['format'] ?? ($info['format'] ?? null),
            'bitrate_bps' => isset($video['bitrate']) ? (int) $video['bitrate'] : (isset($info['bitrate']) ? (int) $info['bitrate'] : null),
            'resolution' => (isset($video['width'], $video['height'])) ? sprintf('%sx%s', $video['width'], $video['height']) : ((isset($info['width'], $info['height'])) ? sprintf('%sx%s', $info['width'], $info['height']) : null),
        ];
    }

    /**
     * @param array<string, mixed>|null $info
     *
     * @return array<string, int|string|null>
     */
    private function extractAudioDetails(?array $info): array
    {
        if ($info === null) {
            return [];
        }

        $audio = $info['audio']['stream'] ?? null;
        if ($audio === null) {
            return [
                'codec' => $info['audio_format'] ?? null,
                'channels' => isset($info['audio_channels']) ? (int) $info['audio_channels'] : null,
                'language' => $info['audio_language'] ?? null,
            ];
        }

        if (isset($audio[0]) && is_array($audio[0])) {
            $audio = $audio[0];
        }

        return [
            'codec' => $audio['format'] ?? null,
            'channels' => isset($audio['channels']) ? (int) $audio['channels'] : null,
            'language' => $audio['language'] ?? null,
        ];
    }

    /**
     * Status endpoint for symmetry with Kra.sk provider.
     * Webshare API (public reference) does not provide direct subscription days in the
     * existing calls we use; we expose token presence and ability to perform a lightweight search.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $token = null;
        try {
            $token = $this->resolveToken();
        } catch (RuntimeException) {
            // ignore
        }
        $hasToken = is_string($token) && $token !== '';
        $vipDays = null;
        $subscriptionActive = null;
        if ($hasToken) {
            try {
                // /api/user_data/ requires POST with wst token.
                $data = $this->post('user_data/', []);
                // Response may wrap data differently; normalize.
                if (isset($data['vip_days'])) {
                    $vipDays = is_numeric($data['vip_days']) ? (int) $data['vip_days'] : null;
                } elseif (isset($data['data']['vip_days'])) {
                    $vipDays = is_numeric($data['data']['vip_days']) ? (int) $data['data']['vip_days'] : null;
                }
                if ($vipDays !== null) {
                    $subscriptionActive = $vipDays > 0;
                }
            } catch (RuntimeException) {
                // Leave vipDays null if the call fails; status still returns token presence.
            }
        }
        return [
            'provider' => 'webshare',
            'authenticated' => $hasToken,
            'token_present' => $hasToken,
            'vip_days' => $vipDays,
            'subscription_active' => $subscriptionActive,
        ];
    }
}
