<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Providers\KraSkApiException;
use App\Providers\KraSkProvider;
use App\Providers\RateLimitDeferredException;

final class KraSkProviderTest extends TestCase
{
    private function providerWithFixtures(array $apiResponses, array $scResponses, array $overrides = []): KraSkProvider
    {
        $apiIdx = 0;
        $scIdx = 0;
        $apiAdapter = function(string $endpoint, array $payload, bool $requireAuth, bool $attachSession, bool $mutateSession, ...$rest) use (&$apiResponses, &$apiIdx) {
            $fixture = $apiResponses[$apiIdx] ?? null;
            $apiIdx++;
            if ($fixture === null || $fixture['endpoint'] !== $endpoint) {
                throw new RuntimeException('Unexpected endpoint ' . $endpoint);
            }
            if (array_key_exists('exception', $fixture)) {
                throw $fixture['exception'];
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
    $config = array_merge([
            'username' => 'u',
            'password' => 'p',
            'uuid' => 'test-uuid', // stable for deterministic query string in tests
            'enrich_limit' => 0, // disable enrichment for predictable test
            'debug' => true,
            'ident_rate_limit_seconds' => 0,
        ], $overrides);

    return new KraSkProvider($config, $apiAdapter, $scAdapter);
    }

    private function primeStreamCinemaToken(KraSkProvider $provider): void
    {
        $ref = new ReflectionClass($provider);
        foreach ([
            'scToken' => 'test-token',
            'lastScTokenTs' => time(),
        ] as $property => $value) {
            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue($provider, $value);
        }
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

    public function testBrowseMenuNormalizesEntries(): void
    {
        $apiResponses = [];
        $scBase = 'https://stream-cinema.online/kodi';
        $defaultParams = 'DV=1&HDR=1&lang=en&skin=default&uid=test-uuid&ver=2.0';
        $scResponses = [
            [
                'url' => $scBase . '/?' . $defaultParams,
                'response' => [
                    'menu' => [
                        [
                            'type' => 'dir',
                            'title' => 'Movies',
                            'url' => '/Movies',
                            'i18n_info' => [
                                'en' => ['title' => 'Movies', 'plot' => 'Browse available films'],
                            ],
                        ],
                        [
                            'type' => 'video',
                            'title' => 'Example Movie',
                            'url' => '/Play/123',
                            'info' => ['year' => 2020, 'rating' => 8.5],
                            'stream_info' => [
                                'video' => ['height' => 1080, 'codec' => 'h264', 'duration' => 5400],
                                'audio' => ['codec' => 'dts', 'channels' => 6],
                                'langs' => ['EN' => 1, 'CZ' => 1],
                            ],
                            'i18n_info' => [
                                'en' => ['title' => 'Example Movie', 'plot' => 'An [B]exciting[/B] film.'],
                            ],
                            'i18n_art' => [
                                'en' => ['thumb' => 'https://example.com/thumb.jpg'],
                            ],
                        ],
                        [
                            'type' => 'video',
                            'title' => 'South Park S01E01',
                            'url' => '/Play/3844/1/1',
                            'strms' => [
                                [
                                    'provider' => 'kraska',
                                    'sid' => '1457729',
                                    'url' => '/ws2/a1SFWQLtgnTlItNoaZ8YCxomi4zkugAM/1457729',
                                ],
                            ],
                        ],
                        [
                            'type' => 'next',
                            'label' => 'Další strana',
                            'url' => 'cmd://NextPage',
                            'path' => '/?page=2',
                        ],
                    ],
                    'system' => ['setPluginCategory' => 'Home'],
                    'filter' => ['view' => 'movies'],
                ],
            ],
        ];
        $provider = $this->providerWithFixtures($apiResponses, $scResponses);
        $this->primeStreamCinemaToken($provider);

        $result = $provider->browseMenu('/');

        $this->assertSame('/', $result['path']);
        $this->assertSame('Home', $result['title']);
        $this->assertArrayHasKey('filter', $result);
        $this->assertCount(4, $result['items']);

        $dir = $result['items'][0];
        $this->assertSame('dir', $dir['type']);
        $this->assertSame('/Movies', $dir['path']);
        $this->assertTrue($dir['selectable']);
        $this->assertSame('branch', $dir['queue_mode']);

        $video = $result['items'][1];
        $this->assertSame('video', $video['type']);
        $this->assertTrue($video['selectable']);
        $this->assertSame('/Play/123', $video['ident']);
        $this->assertSame('kraska', $video['provider']);
        $this->assertSame('single', $video['queue_mode']);
        $this->assertSame(2020, $video['meta']['year']);
        $this->assertSame('1080p H264', $video['meta']['quality']);
        $this->assertSame(['EN', 'CZ'], $video['meta']['languages']);
        $this->assertSame('An exciting film.', $video['summary']);
        $this->assertSame('https://example.com/thumb.jpg', $video['art']['thumb']);

        $episode = $result['items'][2];
        $this->assertSame('video', $episode['type']);
        $this->assertSame('/Play/3844/1/1', $episode['ident']);
        $this->assertTrue($episode['selectable']);
        $this->assertSame('single', $episode['queue_mode']);

        $next = $result['items'][3];
        $this->assertSame('dir', $next['type']);
        $this->assertSame('/?page=2', $next['path']);
        $this->assertFalse($next['selectable']);
        $this->assertArrayNotHasKey('queue_mode', $next);
        $this->assertTrue($next['meta']['pagination']);
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
        $provider = $this->providerWithFixtures($apiResponses, $scResponses, ['ident_rate_limit_seconds' => 2]);

        $firstLink = $provider->resolveDownloadUrl('/Play/1234');
        $this->assertSame('https://cdn.kra.sk/download/alpha.mkv', $firstLink);

        try {
            $provider->resolveDownloadUrl('/Play/5678');
            $this->fail('Expected rate limit deferral.');
        } catch (RateLimitDeferredException $exception) {
            $this->assertGreaterThanOrEqual(1, $exception->getRetryAfterSeconds());
        }
    }

    public function testResolveDownloadUrlRecoversFromInvalidIdent(): void
    {
        $invalid = new KraSkApiException(
            400,
            '/api/file/download',
            ['data' => ['ident' => '1131542']],
            'https://api.kra.sk/api/file/download',
            '{"error":1207,"msg":"invalid ident"}'
        );

        $apiResponses = [
            ['endpoint' => '/api/user/login', 'response' => ['session_id' => 'sess123']],
            ['endpoint' => '/api/file/download', 'exception' => $invalid],
            ['endpoint' => '/api/file/download', 'response' => ['data' => ['link' => 'https://cdn.kra.sk/download/kra-1131542.mkv']]],
        ];

        $scBase = 'https://stream-cinema.online/kodi';
        $defaultParams = 'DV=1&HDR=1&lang=en&skin=default&uid=test-uuid&ver=2.0';
        $scResponses = [
            [
                'url' => $scBase . '/Play/1131542?' . $defaultParams,
                'response' => [
                    'strms' => [
                        [
                            'provider' => 'kraska',
                            'ident' => 'kra-1131542',
                        ],
                    ],
                ],
            ],
            [
                'url' => $scBase . '/Play/1131542?' . $defaultParams,
                'response' => [
                    'strms' => [
                        [
                            'provider' => 'kraska',
                            'ident' => 'kra-1131542',
                        ],
                    ],
                ],
            ],
        ];
        $provider = $this->providerWithFixtures($apiResponses, $scResponses);
        $this->primeStreamCinemaToken($provider);

        $link = $provider->resolveDownloadUrl('1131542');

        $this->assertSame('https://cdn.kra.sk/download/kra-1131542.mkv', $link);
    }

    public function testResolveDownloadUrlDerivesIdentForNumericValue(): void
    {
        $apiCalls = [];
        $self = $this;
        $apiAdapter = function (string $endpoint, array $payload, bool $requireAuth, bool $attachSession, bool $mutateSession, ...$rest) use (&$apiCalls, $self) {
            $apiCalls[] = ['endpoint' => $endpoint, 'payload' => $payload];
            if ($endpoint === '/api/user/login') {
                return ['session_id' => 'sess123'];
            }

            $self->assertSame('/api/file/download', $endpoint);
            $self->assertSame('kra-1457729', $payload['data']['ident']);

            return ['data' => ['link' => 'https://cdn.kra.sk/download/south-park-s01e01.mkv']];
        };

        $scBase = 'https://stream-cinema.online/kodi';
        $defaultParams = 'DV=1&HDR=1&lang=en&skin=default&uid=test-uuid&ver=2.0';
        $scFixtures = [
            [
                'url' => $scBase . '/Play/1457729?' . $defaultParams,
                'response' => [
                    'strms' => [
                        [
                            'provider' => 'kraska',
                            'sid' => '1457729',
                            'url' => '/ws2/a1SFWQLtgnTlItNoaZ8YCxomi4zkugAM/1457729',
                        ],
                    ],
                ],
            ],
            [
                'url' => $scBase . '/ws2/a1SFWQLtgnTlItNoaZ8YCxomi4zkugAM/1457729?' . $defaultParams,
                'response' => ['ident' => 'kra-1457729'],
            ],
        ];

        $scAdapter = function (string $url, bool $withAuth, $provider = null) use (&$scFixtures, $self) {
            $fixture = array_shift($scFixtures);
            $self->assertNotNull($fixture, 'Unexpected Stream-Cinema URL: ' . $url);
            $self->assertSame($fixture['url'], $url);
            return $fixture['response'];
        };

        $provider = new KraSkProvider([
            'username' => 'u',
            'password' => 'p',
            'uuid' => 'test-uuid',
            'debug' => true,
        ], $apiAdapter, $scAdapter);
        $this->primeStreamCinemaToken($provider);

        $link = $provider->resolveDownloadUrl('1457729');

        $this->assertSame('https://cdn.kra.sk/download/south-park-s01e01.mkv', $link);

        $downloadCalls = array_filter(
            $apiCalls,
            static fn (array $call) => $call['endpoint'] === '/api/file/download'
        );
        $this->assertCount(1, $downloadCalls);
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

    public function testSanitizePayloadForLoggingMasksSensitiveValues(): void
    {
        $provider = new KraSkProvider();
        $ref = new ReflectionClass($provider);
        $method = $ref->getMethod('sanitizePayloadForLogging');
        $method->setAccessible(true);

        $payload = [
            'session_id' => 'abc123',
            'data' => [
                'ident' => 'kra-987',
                'password' => 'secret',
                'token' => 'tok',
                'nested' => [
                    'session_id' => 'inner',
                ],
            ],
            'password' => 'outer-secret',
            'token' => 'outer-token',
            'ident' => 'kra-987',
        ];

        /** @var array<string,mixed> $sanitized */
        $sanitized = $method->invoke($provider, $payload);

        $this->assertSame('***', $sanitized['session_id']);
        $this->assertSame('***', $sanitized['password']);
        $this->assertSame('***', $sanitized['token']);
        $this->assertSame('kra-987', $sanitized['ident']);
        $this->assertSame('***', $sanitized['data']['password']);
        $this->assertSame('***', $sanitized['data']['token']);
        $this->assertSame('kra-987', $sanitized['data']['ident']);
        $this->assertSame('***', $sanitized['data']['nested']['session_id']);
    }

    public function testListDownloadOptionsReturnsStreamVariants(): void
    {
        $apiResponses = [
            ['endpoint' => '/api/user/login', 'response' => ['session_id' => 'sess123']],
        ];

        $scBase = 'https://stream-cinema.online/kodi';
        $defaultParams = 'DV=1&HDR=1&lang=en&skin=default&uid=test-uuid&ver=2.0';
        $scResponses = [
            [
                'url' => $scBase . '/Play/123?' . $defaultParams,
                'response' => [
                    'strms' => [
                        [
                            'provider' => 'kraska',
                            'ident' => 'kra-123',
                            'quality' => '1080p',
                            'size' => 2_000_000_000,
                            'stream_info' => [
                                'video' => ['codec' => 'h265', 'height' => 1080, 'duration' => 5400],
                                'audio' => ['codec' => 'dts', 'channels' => 6],
                            ],
                            'langs' => ['CZ' => 1],
                        ],
                        [
                            'provider' => 'kraska',
                            'sid' => 'kra-456',
                            'quality' => '720p',
                            'url' => '/ws2/token/xyz',
                            'size' => 1_500_000_000,
                            'langs' => ['EN' => 1],
                        ],
                    ],
                ],
            ],
            [
                'url' => $scBase . '/ws2/token/xyz?' . $defaultParams,
                'response' => ['ident' => 'kra-456'],
            ],
        ];

    $provider = $this->providerWithFixtures($apiResponses, $scResponses);
        $this->primeStreamCinemaToken($provider);

        $variants = $provider->listDownloadOptions('/Play/123');

        $this->assertCount(2, $variants);
        $this->assertSame('kra-123', $variants[0]['id']);
        $this->assertSame('1080p', $variants[0]['quality']);
        $this->assertSame('kra-456', $variants[1]['id']);
        $this->assertSame('720p', $variants[1]['quality']);
    }

    public function testListDownloadOptionsResolvesNumericSidToKraIdent(): void
    {
        $apiResponses = [
            ['endpoint' => '/api/user/login', 'response' => ['session_id' => 'sess123']],
        ];

        $scBase = 'https://stream-cinema.online/kodi';
        $defaultParams = 'DV=1&HDR=1&lang=en&skin=default&uid=test-uuid&ver=2.0';
        $scResponses = [
            [
                'url' => $scBase . '/Play/14264/1/1?' . $defaultParams,
                'response' => [
                    'strms' => [
                        [
                            'provider' => 'kraska',
                            'sid' => '1131542',
                            'url' => '/ws2/token/family-guy/1131542',
                            'quality' => '1080p',
                        ],
                    ],
                ],
            ],
            [
                'url' => $scBase . '/ws2/token/family-guy/1131542?' . $defaultParams,
                'response' => ['ident' => 'kra-1131542'],
            ],
        ];

    $provider = $this->providerWithFixtures($apiResponses, $scResponses);
        $this->primeStreamCinemaToken($provider);

        $variants = $provider->listDownloadOptions('/Play/14264/1/1');

        $this->assertCount(1, $variants);
        $this->assertSame('kra-1131542', $variants[0]['id']);
        $this->assertSame('kra-1131542', $variants[0]['kra_ident']);
    }

    public function testListDownloadOptionsFallsBackToProvidedIdent(): void
    {
        $provider = new KraSkProvider();
        $variants = $provider->listDownloadOptions('kra-999');

        $this->assertCount(1, $variants);
        $this->assertSame('kra-999', $variants[0]['id']);
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
