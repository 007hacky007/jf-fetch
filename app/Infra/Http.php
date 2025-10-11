<?php

declare(strict_types=1);

namespace App\Infra;

use JsonException;
use RuntimeException;

/**
 * Small HTTP helper utilities for API endpoints.
 */
final class Http
{
    private function __construct()
    {
    }

    /**
     * Decodes the JSON request body into an associative array.
     *
     * @return array<string, mixed>
     */
    public static function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false) {
            throw new RuntimeException('Failed to read request body.');
        }

        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid JSON payload: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('JSON payload must decode to an object.');
        }

        return $decoded;
    }

    /**
     * Sends a JSON response with the provided HTTP status code.
     *
     * @param array<string, mixed> $payload
     */
    public static function json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Convenience wrapper for sending JSON error payloads.
     *
     * @param array<string, mixed> $meta
     */
    public static function error(int $statusCode, string $message, array $meta = []): void
    {
        $payload = array_merge(['error' => $message], $meta);
        self::json($statusCode, $payload);
    }
}
