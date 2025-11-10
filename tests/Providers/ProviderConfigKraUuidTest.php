<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Providers\ProviderConfig;

final class ProviderConfigKraUuidTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('KRA_SKIP_VALIDATE=1');
    }

    public function testAcceptsCustomUuid(): void
    {
        $uuid = '87rkcg48-hv02-47w5-ss36-d8x82r8cmqdv';
        $config = [
            'username' => 'user1',
            'password' => 'pass1',
            'uuid' => $uuid,
        ];

        $out = ProviderConfig::prepare('kraska', $config);
        $this->assertSame($uuid, $out['uuid'] ?? null);
    }

    public function testNormalizesRateLimitToInteger(): void
    {
        $config = [
            'username' => 'user1',
            'password' => 'pass1',
            'ident_rate_limit_seconds' => '240',
        ];

        $out = ProviderConfig::prepare('kraska', $config);
        $this->assertSame(240, $out['ident_rate_limit_seconds'] ?? null);
    }

    public function testRejectsInvalidRateLimit(): void
    {
        $config = [
            'username' => 'user1',
            'password' => 'pass1',
            'ident_rate_limit_seconds' => 'not-a-number',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Kra.sk ident rate limit must be a positive whole number of seconds.');

        ProviderConfig::prepare('kraska', $config);
    }

    public function testAppliesDefaultRateLimitWhenMissing(): void
    {
        $config = [
            'username' => 'user1',
            'password' => 'pass1',
        ];

        $out = ProviderConfig::prepare('kraska', $config);
        $this->assertSame(120, $out['ident_rate_limit_seconds'] ?? null);
    }
}
