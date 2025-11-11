<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Jobs;
use App\Tests\TestCase;
use DateTimeImmutable;
use PDO;

final class JobsPagedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDefaultConfig();
        $this->pdo = $this->useInMemoryDatabase();
        $this->createSchema();
        $this->seedFixtures();
    }

    public function testListPagedReturnsCorrectTotalAndOrder(): void
    {
        // Fetch first page (limit 3)
        $page1 = Jobs::listPaged(true, 1, false, 3, 0);
        $this->assertSame(5, $page1['total']);
        $this->assertCount(3, $page1['rows']);

        // Ensure newest created_at first ordering
        $firstTs = $page1['rows'][0]['created_at'];
        $secondTs = $page1['rows'][1]['created_at'];
        $thirdTs = $page1['rows'][2]['created_at'];
        $this->assertTrue($firstTs >= $secondTs, 'First row should be >= second by created_at DESC');
        $this->assertTrue($secondTs >= $thirdTs, 'Second row should be >= third by created_at DESC');

        // Fetch second page
        $page2 = Jobs::listPaged(true, 1, false, 3, 3);
        $this->assertSame(5, $page2['total']);
        $this->assertCount(2, $page2['rows']);

        // No overlap between pages (IDs distinct)
        $idsPage1 = array_map(static fn($r) => (int) $r['id'], $page1['rows']);
        $idsPage2 = array_map(static fn($r) => (int) $r['id'], $page2['rows']);
        $this->assertSame([], array_intersect($idsPage1, $idsPage2));
    }

    public function testListPagedRespectsMineOnlyAndAdminFalse(): void
    {
        // Non-admin requesting only their jobs
        $page = Jobs::listPaged(false, 2, true, 10, 0);
        $this->assertSame(2, $page['total']);
        $ids = array_map(static fn($r) => (int) $r['user_id'], $page['rows']);
        $this->assertSame([2, 2], $ids);
    }

    private function createSchema(): void
    {
        $pdo = $this->connection();

        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            password_hash TEXT NULL,
            role TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        $pdo->exec('CREATE TABLE providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            key TEXT NOT NULL,
            name TEXT NOT NULL
        )');

        $pdo->exec('CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            provider_id INTEGER NOT NULL,
            external_id TEXT NOT NULL,
            title TEXT NOT NULL,
            source_url TEXT NOT NULL,
            category TEXT NULL,
            status TEXT NOT NULL,
            progress INTEGER NOT NULL DEFAULT 0,
            speed_bps INTEGER NULL,
            eta_seconds INTEGER NULL,
            priority INTEGER NOT NULL DEFAULT 100,
            position INTEGER NOT NULL DEFAULT 0,
            aria2_gid TEXT NULL,
            tmp_path TEXT NULL,
            final_path TEXT NULL,
            file_size_bytes INTEGER NULL,
            error_text TEXT NULL,
            metadata_json TEXT NULL,
            deleted_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (provider_id) REFERENCES providers(id)
        )');
    }

    private function seedFixtures(): void
    {
        $pdo = $this->connection();

        $now = new DateTimeImmutable('2025-01-01T00:00:00+00:00');

        $insertUser = $pdo->prepare('INSERT INTO users (id, name, email, password_hash, role, created_at, updated_at) VALUES (:id, :name, :email, :password_hash, :role, :created_at, :updated_at)');
        $insertUser->execute([
            'id' => 1,
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'role' => 'admin',
            'created_at' => $now->format('Y-m-d\TH:i:s.uP'),
            'updated_at' => $now->format('Y-m-d\TH:i:s.uP'),
        ]);
        $insertUser->execute([
            'id' => 2,
            'name' => 'User Two',
            'email' => 'user2@example.test',
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'role' => 'user',
            'created_at' => $now->format('Y-m-d\TH:i:s.uP'),
            'updated_at' => $now->format('Y-m-d\TH:i:s.uP'),
        ]);

        $pdo->exec("INSERT INTO providers (id, key, name) VALUES (1, 'webshare', 'Webshare')");

        $insertJob = $pdo->prepare('INSERT INTO jobs (
            id, user_id, provider_id, external_id, title, source_url, category, status, progress, speed_bps,
            eta_seconds, priority, position, aria2_gid, tmp_path, final_path, error_text, metadata_json, deleted_at,
            created_at, updated_at
        ) VALUES (
            :id, :user_id, :provider_id, :external_id, :title, :source_url, :category, :status, :progress,
            :speed_bps, :eta_seconds, :priority, :position, :aria2_gid, :tmp_path, :final_path, :error_text,
            :metadata_json, :deleted_at, :created_at, :updated_at
        )');

        // Create five jobs with ascending created_at timestamps so DESC ordering is deterministic
        for ($i = 1; $i <= 5; $i++) {
            $created = $now->modify('+' . $i . ' minutes');
            $insertJob->execute([
                'id' => $i,
                'user_id' => $i <= 3 ? 1 : 2, // first 3 admin, last 2 user 2
                'provider_id' => 1,
                'external_id' => 'ext-' . $i,
                'title' => 'Job #' . $i,
                'source_url' => 'https://example.test/file' . $i,
                'category' => null,
                'status' => 'queued',
                'progress' => 0,
                'speed_bps' => null,
                'eta_seconds' => null,
                'priority' => 100 + $i,
                'position' => $i,
                'aria2_gid' => null,
                'tmp_path' => null,
                'final_path' => null,
                'error_text' => null,
                'metadata_json' => null,
                'deleted_at' => null,
                'created_at' => $created->format('Y-m-d\TH:i:s.uP'),
                'updated_at' => $created->format('Y-m-d\TH:i:s.uP'),
            ]);
        }
    }

    private function connection(): PDO
    {
        $this->assertInstanceOf(PDO::class, $this->pdo);
        return $this->pdo;
    }
}
