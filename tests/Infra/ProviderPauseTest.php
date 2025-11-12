<?php

declare(strict_types=1);

namespace App\Tests\Infra;

require_once __DIR__ . '/../Support/Require.php';

use App\Infra\ProviderPause;
use App\Tests\TestCase;
use PDO;

final class ProviderPauseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDefaultConfig();
        $pdo = $this->useInMemoryDatabase();
        $this->createSchema($pdo);
        $this->seedProviders($pdo);
    }

    public function testSetActiveAndClear(): void
    {
        ProviderPause::set('webshare', [
            'provider_id' => 1,
            'provider_label' => 'Webshare',
            'note' => 'Maintenance',
            'paused_by' => 'Admin',
            'paused_by_id' => 99,
        ]);

        $active = ProviderPause::active();
        $this->assertCount(1, $active);
        $entry = $active[0];
        $this->assertSame('webshare', $entry['provider']);
        $this->assertSame(1, $entry['provider_id']);
        $this->assertSame('Webshare', $entry['provider_label']);
        $this->assertSame('Maintenance', $entry['note']);
        $this->assertSame(99, $entry['paused_by_id']);
        $this->assertSame('paused', $entry['type']);

        $ids = ProviderPause::providerIds();
        $this->assertSame([1], $ids);

        ProviderPause::clear('webshare');
        $this->assertSame([], ProviderPause::active());
        $this->assertSame([], ProviderPause::providerIds());
    }

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            key TEXT NOT NULL,
            name TEXT NOT NULL
        )');

        $pdo->exec('CREATE TABLE settings (
            key TEXT PRIMARY KEY,
            value TEXT,
            type TEXT,
            updated_at TEXT
        )');
    }

    private function seedProviders(PDO $pdo): void
    {
        $stmt = $pdo->prepare('INSERT INTO providers (id, key, name) VALUES (:id, :key, :name)');
        $stmt->execute([
            'id' => 1,
            'key' => 'webshare',
            'name' => 'Webshare',
        ]);
    }
}
