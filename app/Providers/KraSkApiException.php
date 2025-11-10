<?php

declare(strict_types=1);

namespace App\Providers;

use RuntimeException;

/**
 * Exception representing a Kra.sk API HTTP error with additional request context.
 */
final class KraSkApiException extends RuntimeException
{
    private int $statusCode;
    private string $endpoint;
    /**
     * @var array<string,mixed>
     */
    private array $payload;
    private string $url;
    private ?string $responseBody;

    /**
     * @param array<string,mixed> $payload Sanitised payload with sensitive values masked.
     */
    public function __construct(int $statusCode, string $endpoint, array $payload, string $url, ?string $responseBody = null)
    {
        parent::__construct('Kra.sk API HTTP status ' . $statusCode);

        $this->statusCode = $statusCode;
        $this->endpoint = $endpoint;
        $this->payload = $payload;
        $this->url = $url;
        $this->responseBody = $responseBody;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return array<string,mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
