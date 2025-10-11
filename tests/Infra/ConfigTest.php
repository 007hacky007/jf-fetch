<?php

declare(strict_types=1);

namespace App\Tests\Infra;

use App\Infra\Config;
use App\Tests\TestCase;
use RuntimeException;

final class ConfigTest extends TestCase
{
    public function testBootMergesIniFiles(): void
    {
        $this->bootConfig([
            'app' => [
                'app' => [
                    'session_name' => 'JF_FETCH_TEST',
                ],
                'db' => [
                    'dsn' => 'sqlite::memory:',
                ],
            ],
            'secret' => [
                'db' => [
                    'pass' => 'secret',
                ],
            ],
        ]);

        $this->assertTrue(Config::has('db.pass'));
        $this->assertSame('secret', Config::get('db.pass'));
        $this->assertSame('sqlite::memory:', Config::get('db.dsn'));
    }

    public function testGetThrowsForMissingKey(): void
    {
        $this->bootConfig([
            'app' => [
                'app' => [
                    'session_name' => 'JF_FETCH_TEST',
                ],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        Config::get('missing.key');
    }

    public function testResetAllowsReboot(): void
    {
        $this->bootConfig([
            'app' => [
                'app' => [
                    'session_name' => 'JF_FETCH_TEST',
                ],
            ],
        ]);

        Config::reset();

        $this->bootConfig([
            'app' => [
                'app' => [
                    'session_name' => 'JF_FETCH_TEST_2',
                ],
            ],
        ]);

        $this->assertSame('JF_FETCH_TEST_2', Config::get('app.session_name'));
    }
}
