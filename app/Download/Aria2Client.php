<?php

declare(strict_types=1);

namespace App\Download;

use App\Infra\Config;
use JsonException;
use RuntimeException;

/**
 * Thin JSON-RPC client for communicating with aria2.
 *
 * Supports core operations required by the scheduler/worker: queuing downloads,
 * inspecting status, and controlling active transfers.
 */
final class Aria2Client
{
    private string $endpoint;

    private ?string $secret;

    private int $timeoutSeconds;

    /**
     * @param string|null $endpoint Optional override of the aria2 RPC URL.
     * @param string|null $secret Optional override of the aria2 RPC secret token.
     * @param int $timeoutSeconds HTTP timeout for RPC requests.
     */
    public function __construct(?string $endpoint = null, ?string $secret = null, int $timeoutSeconds = 15)
    {
        $this->endpoint = $endpoint ?? (string) Config::get('aria2.rpc_url');

        $configuredSecret = Config::has('aria2.secret') ? (string) Config::get('aria2.secret') : null;
        $normalizedConfigured = $this->normalizeSecret($configuredSecret);

        $envSecret = getenv('ARIA2_SECRET');
        $normalizedEnv = $this->normalizeSecret($envSecret === false ? null : $envSecret);

        if ($secret !== null) {
            $this->secret = $this->normalizeSecret($secret);
        } elseif ($normalizedConfigured !== null && strcasecmp($normalizedConfigured, 'changeme') !== 0) {
            $this->secret = $normalizedConfigured;
        } elseif ($normalizedEnv !== null) {
            $this->secret = $normalizedEnv;
        } else {
            $this->secret = $normalizedConfigured ?? $normalizedEnv;
        }

        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * Adds one or more URIs to the aria2 queue.
     *
     * @param array<int, string> $uris Direct download URIs.
     * @param array<string, mixed> $options Optional aria2 options (see aria2 docs).
     *
     * @return string GID of the enqueued download.
     */
    public function addUri(array $uris, array $options = []): string
    {
        if ($uris === []) {
            throw new RuntimeException('At least one download URI must be provided.');
        }

        $normalizedUris = [];
        foreach ($uris as $uri) {
            if (!is_string($uri)) {
                throw new RuntimeException('Download URIs must be strings.');
            }

            $trimmed = trim($uri);
            if ($trimmed === '') {
                continue;
            }

            $normalizedUris[] = $trimmed;
        }

        if ($normalizedUris === []) {
            throw new RuntimeException('At least one download URI must be provided.');
        }

        $params = [$normalizedUris];
        if ($options !== []) {
            $params[] = $options;
        }

        /** @var string $gid */
        $gid = $this->request('aria2.addUri', $params);

        return $gid;
    }

    /**
     * Retrieves aria2 status information for a specific download.
     *
     * @param string $gid Aria2 global identifier.
     * @param array<int, string> $keys Optional list of status keys to limit the response.
     *
     * @return array<string, mixed>
     */
    public function tellStatus(string $gid, array $keys = []): array
    {
        $params = [$gid];
        if ($keys !== []) {
            $params[] = $keys;
        }

        /** @var array<string, mixed> $status */
        $status = $this->request('aria2.tellStatus', $params);

        return $status;
    }

    /**
     * Pauses an active download.
     *
     * @return string GID of the paused task.
     */
    public function pause(string $gid): string
    {
        /** @var string $result */
        $result = $this->request('aria2.pause', [$gid]);

        return $result;
    }

    /**
     * Resumes a paused download.
     *
     * @return string GID of the resumed task.
     */
    public function unpause(string $gid): string
    {
        /** @var string $result */
        $result = $this->request('aria2.unpause', [$gid]);

        return $result;
    }

    /**
     * Removes a download from aria2 (optionally forcefully stops active transfers).
     *
     * @param bool $force When true, uses aria2.forceRemove instead of aria2.remove.
     *
     * @return string GID of the removed task.
     */
    public function remove(string $gid, bool $force = false): string
    {
        $method = $force ? 'aria2.forceRemove' : 'aria2.remove';

        /** @var string $result */
        $result = $this->request($method, [$gid]);

        return $result;
    }

    /**
     * Sends a JSON-RPC request to aria2 and returns the result field.
     *
     * @param array<int, mixed> $params
     *
     * @return mixed
     */
    private function request(string $method, array $params = []): mixed
    {
        $token = $this->secret !== null ? 'token:' . $this->secret : null;
        if ($token !== null) {
            array_unshift($params, $token);
        }

        $payload = [
            'jsonrpc' => '2.0',
            'id' => uniqid('aria2_', true),
            'method' => $method,
            'params' => $params,
        ];

        $ch = curl_init($this->endpoint);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for aria2 request.');
        }

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('aria2 RPC request failed: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('aria2 RPC returned unexpected HTTP status: ' . $status);
        }

        try {
            /** @var array{result?:mixed,error?:array<string,mixed>} $decoded */
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to decode aria2 RPC response: ' . $exception->getMessage(), 0, $exception);
        }

        if (isset($decoded['error'])) {
            $message = is_array($decoded['error']) ? ($decoded['error']['message'] ?? 'Unknown aria2 error') : 'Unknown aria2 error';
            throw new RuntimeException('aria2 RPC error: ' . $message);
        }

        return $decoded['result'] ?? null;
    }

    private function normalizeSecret(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
