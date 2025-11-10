<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Providers\KraSkProvider;
use App\Providers\RateLimitDeferredException;

final class KraSkProviderTest extends TestCase
{
    private function providerWithFixtures(array $apiResponses, array $scResponses): KraSkProvider
    {
        $apiIdx = 0;
        $scIdx = 0;
    $apiAdapter = function(string $endpoint, array $payload, bool $requireAuth, bool $attachSession, bool $mutateSession, ...$rest) use (&$apiResponses, &$apiIdx) {
            $fixture = $apiResponses[$apiIdx] ?? null;
            $apiIdx++;
            if ($fixture === null || $fixture['endpoint'] !== $endpoint) {
                throw new RuntimeException('Unexpected endpoint ' . $endpoint);
            }
            return $fixture['response'];
        };
        $scAdapter = function(string $url, bool $withAuth, $provider = null) use (&$scResponses, &$scIdx) {
            $fixture = $scResponses[$scIdx] ?? null;
            $scIdx++;
            if ($fixture === null || $fixture['url'] !== $url) {
                throw new RuntimeException('Unexpected SC url ' . $url);
            }
            return $fixture['response'];
        };
        return new KraSkProvider([
            'username' => 'u',
            'password' => 'p',
            'uuid' => 'test-uuid', // stable for deterministic query string in tests
            'enrich_limit' => 0, // disable enrichment for predictable test
            'debug' => true,
        ], $apiAdapter, $scAdapter);
    }

    public function testSearchNormalizesResults(): void
    {
        $apiResponses = [
            // login
            ['endpoint' => '/api/user/login', 'response' => [ 'session_id' => 'sess123' ]],
            // user info when status() invoked implicitly (not in this test)
        ];
        $scBase = 'https://stream-cinema.online/kodi';
        $scResponses = [
            [
                // Query params sorted alphabetically (ksort in provider)
                'url' => $scBase . '/Search/search?DV=1&HDR=1&id=search&lang=en&search=movie&skin=default&uid=test-uuid&ver=2.0',
                'response' => [
                    'menu' => [
                        ['id' => 'abc', 'title' => 'Movie A', 'size' => 1024, 'type' => 'video'],
                        ['id' => 'def', 'title' => 'Movie B', 'size' => 2048, 'type' => 'video'],
                    ],
                ],
            ],
        ];
        $provider = $this->providerWithFixtures($apiResponses, $scResponses);
    $results = $provider->search('movie', 2); // limit triggers early break after first category
        $this->assertCount(2, $results);
    $this->assertSame('/Play/abc', $results[0]['id']);
        $this->assertSame('kraska', $results[0]['provider']);
    }

    public function testResolveReturnsLink(): void
    {
        $apiResponses = [
            ['endpoint' => '/api/user/login', 'response' => [ 'session_id' => 'sess123' ]],
            ['endpoint' => '/api/file/download', 'response' => [ 'data' => ['link' => 'https://cdn.kra.sk/download/abc.mp4']]],
        ];
        $scResponses = [];
        $provider = $this->providerWithFixtures($apiResponses, $scResponses);
        $url = $provider->resolveDownloadUrl('abc');
        $this->assertSame('https://cdn.kra.sk/download/abc.mp4', $url);
    }

    public function testResolveDownloadUrlDefersWhenRateLimited(): void
    {
        $apiResponses = [
            ['endpoint' => '/api/user/login', 'response' => ['session_id' => 'sess123']],
            ['endpoint' => '/api/file/download', 'response' => ['data' => ['link' => 'https://cdn.kra.sk/download/alpha.mkv']]],
        ];

        $scBase = 'https://stream-cinema.online/kodi';
        $defaultParams = 'DV=1&HDR=1&lang=en&skin=default&uid=test-uuid&ver=2.0';
        $scResponses = [
            [
                'url' => $scBase . '/Play/1234?' . $defaultParams,
                'response' => [
                    'strms' => [
                        [
                            'provider' => 'kraska',
                            'ident' => 'kra-1234',
                            'sid' => 'kra-1234',
                            'url' => '/ws2/token/file',
                        ],
                    ],
                ],
            ],
            [
                'url' => $scBase . '/ws2/token/file?' . $defaultParams,
                'response' => ['ident' => 'kra-1234'],
            ],
        ];

        $provider = $this->providerWithFixtures($apiResponses, $scResponses);

        $firstLink = $provider->resolveDownloadUrl('/Play/1234');
        $this->assertSame('https://cdn.kra.sk/download/alpha.mkv', $firstLink);

        try {
            $provider->resolveDownloadUrl('/Play/5678');
            $this->fail('Expected rate limit deferral.');
        } catch (RateLimitDeferredException $exception) {
            $this->assertGreaterThanOrEqual(1, $exception->getRetryAfterSeconds());
        }
    }

    public function testIdentRateLimitDefaultsTo120Seconds(): void
    {
        $provider = new KraSkProvider();
        $this->assertSame(120, $this->extractIdentRateLimit($provider));
    }

    public function testIdentRateLimitReadsProviderConfig(): void
    {
        $provider = new KraSkProvider(['ident_rate_limit_seconds' => 45]);
        $this->assertSame(45, $this->extractIdentRateLimit($provider));
    }

    private function extractIdentRateLimit(KraSkProvider $provider): int
    {
        $ref = new ReflectionClass($provider);
        $method = $ref->getMethod('getIdentFetchRateLimitSeconds');
        $method->setAccessible(true);

        /** @var int $value */
        $value = $method->invoke($provider);

        return $value;
    }
}
