<?php

declare(strict_types=1);

namespace App\Providers;

use RuntimeException;
use Throwable;

/**
 * Raised when a provider defers an operation due to hitting a rate limit.
 */
class RateLimitDeferredException extends RuntimeException
{
	private int $retryAfterSeconds;

	public function __construct(int $retryAfterSeconds, string $message = 'Stream-Cinema ident fetch deferred due to rate limit', ?Throwable $previous = null)
	{
		parent::__construct($message, 0, $previous);
		$this->retryAfterSeconds = max(1, $retryAfterSeconds);
	}

	public function getRetryAfterSeconds(): int
	{
		return $this->retryAfterSeconds;
	}
}