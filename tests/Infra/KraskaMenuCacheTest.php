<?php

declare(strict_types=1);

namespace App\Tests\Infra;

use App\Infra\KraskaMenuCache;
use PHPUnit\Framework\TestCase;

final class KraskaMenuCacheTest extends TestCase
{
    private ?string $dbPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/kraska-cache-' . bin2hex(random_bytes(6)) . '.sqlite';
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
        KraskaMenuCache::useCustomPath($this->dbPath);
    }

    protected function tearDown(): void
    {
        KraskaMenuCache::useCustomPath(null);
        KraskaMenuCache::resetConnection();
        if ($this->dbPath && file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
        $this->dbPath = null;
        parent::tearDown();
    }

    public function testPutAndGetReturnsCachedPayload(): void
    {
        $payload = ['path' => '/test', 'title' => 'Demo', 'items' => [['label' => 'Item']]];
        $meta = KraskaMenuCache::put('kraska', 'sig1', '/test', $payload);

        $this->assertArrayHasKey('fetched_at', $meta);
        $this->assertArrayHasKey('fetched_ts', $meta);

        $cached = KraskaMenuCache::get('kraska', 'sig1', '/test', 600);
        $this->assertNotNull($cached);
        $this->assertSame($payload, $cached['data']);
        $this->assertSame($meta['fetched_at'], $cached['fetched_at']);
    }

    public function testExpiredEntryReturnsNull(): void
    {
        KraskaMenuCache::put('kraska', 'sig-expire', '/expire', ['items' => []]);

        $cached = KraskaMenuCache::get('kraska', 'sig-expire', '/expire', 0);
        $this->assertNull($cached);
    }

    public function testSignatureMismatchInvalidatesEntry(): void
    {
        KraskaMenuCache::put('kraska', 'sig-a', '/path', ['items' => []]);

        $mismatch = KraskaMenuCache::get('kraska', 'sig-b', '/path', 600);
        $this->assertNull($mismatch);

        $again = KraskaMenuCache::get('kraska', 'sig-a', '/path', 600);
        $this->assertNull($again, 'Cache entry should be removed after signature mismatch.');
    }
}
