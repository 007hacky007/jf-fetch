<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Providers\ProviderConfig;

final class ProviderConfigKraUuidTest extends TestCase
{
    public function testAcceptsCustomUuid(): void
    {
        $uuid = '87rkcg48-hv02-47w5-ss36-d8x82r8cmqdv';
        putenv('KRA_SKIP_VALIDATE=1');
        $config = [
            'username' => 'user1',
            'password' => 'pass1',
            'uuid' => $uuid,
        ];

        $out = ProviderConfig::prepare('kraska', $config);
        $this->assertSame($uuid, $out['uuid'] ?? null);
    }
}
