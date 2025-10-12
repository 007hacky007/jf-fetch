<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Providers\KraSkProvider;

final class KraSkProviderMetadataTest extends TestCase
{
    private function providerWithFixtures(array $apiResponses, array $scResponses): KraSkProvider
    {
        $apiIdx = 0; $scIdx = 0;
        $apiAdapter = function(string $endpoint, array $payload, bool $requireAuth, bool $attachSession, bool $mutateSession) use (&$apiResponses, &$apiIdx) {
            $fixture = $apiResponses[$apiIdx] ?? null; $apiIdx++;
            if ($fixture === null || $fixture['endpoint'] !== $endpoint) { throw new RuntimeException('Unexpected endpoint ' . $endpoint); }
            return $fixture['response'];
        };
        $scAdapter = function(string $url, bool $withAuth) use (&$scResponses, &$scIdx) {
            $fixture = $scResponses[$scIdx] ?? null; $scIdx++;
            if ($fixture === null || $fixture['url'] !== $url) { throw new RuntimeException('Unexpected SC url ' . $url); }
            return $fixture['response'];
        };
        return new KraSkProvider([
            'username' => 'u',
            'password' => 'p',
            'uuid' => 'meta-uuid',
            'enrich_limit' => 0,
        ], $apiAdapter, $scAdapter);
    }

    public function testMetadataExtractionFromStreamInfo(): void
    {
        $apiResponses = [ ['endpoint' => '/api/user/login', 'response' => ['session_id' => 'sessMeta']] ];
        $scBase = 'https://stream-cinema.online/kodi';
        $qs = 'DV=1&HDR=1&id=search&lang=en&search=sample&skin=default&uid=meta-uuid&ver=2.0';
        $scResponses = [[
            'url' => $scBase . '/Search/search?' . $qs,
            'response' => [ 'menu' => [[
                'id' => '123', 'title' => 'Sample Movie', 'type' => 'video',
                'stream_info' => [
                    'video' => ['codec' => 'h264', 'width' => 1920, 'height' => 1080, 'duration' => 3600],
                    'audio' => ['codec' => 'aac', 'channels' => 2],
                    'fps' => 24,
                    'langs' => ['EN' => 1]
                ]
            ]] ],
        ]];
        $provider = $this->providerWithFixtures($apiResponses, $scResponses);
    // Limit 1 so provider stops after first search category fixture.
    $results = $provider->search('sample', 1);
        $this->assertCount(1, $results);
        $r = $results[0];
        $this->assertSame('/Play/123', $r['id']);
        $this->assertSame('h264', $r['video_codec']);
        $this->assertSame(1080, $r['height']);
        $this->assertSame(1920, $r['width']);
        $this->assertSame(2, $r['audio_channels']);
        $this->assertSame('24', (string)$r['fps']);
        $this->assertSame(3600, $r['duration_seconds']);
    }
}
