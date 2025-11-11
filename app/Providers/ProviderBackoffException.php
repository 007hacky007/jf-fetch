<?php

declare(strict_types=1);

namespace App\Providers;

use RuntimeException;
use Throwable;

/**
 * Signals that a provider should be temporarily paused before retrying jobs.
 */
final class ProviderBackoffException extends RuntimeException
{
    private string $providerKey;
    private int $providerId;
    private int $retryAfterSeconds;
    /**
     * @var array<string, mixed>
     */
    private array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $providerKey, int $providerId, int $retryAfterSeconds, string $message, array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->providerKey = $providerKey;
        $this->providerId = $providerId;
        $this->retryAfterSeconds = max(1, $retryAfterSeconds);
        $this->context = $context;
    }

    public function getProviderKey(): string
    {
        return $this->providerKey;
    }

    public function getProviderId(): int
    {
        return $this->providerId;
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
