<?php

declare(strict_types=1);

namespace App\Tests\Infra;

use App\Infra\ProviderRateLimiter;
use App\Tests\TestCase;

final class ProviderRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->useInMemoryDatabase();
        $pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT, type TEXT, updated_at TEXT)');
    }

    public function testAcquireHonorsMinimumSpacing(): void
    {
        ProviderRateLimiter::clear('unittest');

        $this->assertNull(ProviderRateLimiter::acquire('unittest', 'bucket', 1));

        $retryAfter = ProviderRateLimiter::acquire('unittest', 'bucket', 1);
        $this->assertSame(1, $retryAfter);

        usleep(1_100_000);
        $this->assertNull(ProviderRateLimiter::acquire('unittest', 'bucket', 1));
    }

    public function testAcquireHonorsBurstWindow(): void
    {
        ProviderRateLimiter::clear('burst');

        $options = ['burst_limit' => 2, 'burst_window_seconds' => 1];

        $this->assertNull(ProviderRateLimiter::acquire('burst', 'bucket', 0, [], $options));
        $this->assertNull(ProviderRateLimiter::acquire('burst', 'bucket', 0, [], $options));

        $retryAfter = ProviderRateLimiter::acquire('burst', 'bucket', 0, [], $options);
        $this->assertSame(1, $retryAfter);

        usleep(1_100_000);
        $this->assertNull(ProviderRateLimiter::acquire('burst', 'bucket', 0, [], $options));
    }
}
