<?php

declare(strict_types=1);

namespace App\Tests\Download;

use App\Download\Aria2Options;
use App\Tests\TestCase;

final class Aria2OptionsTest extends TestCase
{
    public function testMaxDownloadLimitBytesReturnsNullWhenDisabled(): void
    {
        $this->bootDefaultConfig([
            'aria2' => [
                'max_speed_mb_s' => 0,
            ],
        ]);

        self::assertNull(Aria2Options::maxDownloadLimitBytes());
    }

    public function testMaxDownloadLimitBytesConvertsMegabytesToBytes(): void
    {
        $this->bootDefaultConfig([
            'aria2' => [
                'max_speed_mb_s' => 12.5,
            ],
        ]);

        $expectedBytes = (int) floor(12.5 * 1024 * 1024);
        self::assertSame($expectedBytes, Aria2Options::maxDownloadLimitBytes());
    }

    public function testApplySpeedLimitInjectsOption(): void
    {
        $this->bootDefaultConfig([
            'aria2' => [
                'max_speed_mb_s' => 5,
            ],
        ]);

        $options = Aria2Options::applySpeedLimit(['dir' => '/downloads']);
        $expectedBytes = (int) floor(5 * 1024 * 1024);

        self::assertSame('/downloads', $options['dir']);
        self::assertSame((string) $expectedBytes, $options['max-download-limit'] ?? null);
    }

    public function testApplySpeedLimitNoopsWhenConfigMissing(): void
    {
        $this->bootConfig([
            'app' => [
                'aria2' => [
                    'rpc_url' => 'http://localhost:6800/jsonrpc',
                    'secret' => '',
                ],
            ],
        ]);

        $options = Aria2Options::applySpeedLimit(['dir' => '/downloads']);
        self::assertArrayNotHasKey('max-download-limit', $options);
    }
}
