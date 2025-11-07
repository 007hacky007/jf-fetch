<?php

declare(strict_types=1);

namespace App\Tests\Integration;

require_once __DIR__ . '/../Support/Require.php';

use App\Infra\Auth;
use App\Infra\Db;
use App\Api\JobsList;
use App\Tests\TestCase;
use DateTimeImmutable;
use PDO;

final class JobsListPaginationEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDefaultConfig();
        $this->pdo = $this->useInMemoryDatabase();
        $this->createSchema();
        $this->seedFixtures();
        Auth::boot();
        // Simulate login by placing user directly in session
        $_SESSION['auth.user'] = [
            'id' => 1,
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'role' => 'admin',
            'created_at' => $this->now()->format(DATE_ATOM),
            'updated_at' => $this->now()->format(DATE_ATOM),
            'last_login_at' => $this->now()->format(DATE_ATOM),
        ];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testPagedListReturnsMetaAndFirstSlice(): void
    {
        $payload = JobsList::handle(true, 1, false, 3, 0);
        $this->assertArrayHasKey('meta', $payload);
        $this->assertSame(6, $payload['meta']['total']);
        $this->assertSame(3, $payload['meta']['limit']);
        $this->assertSame(0, $payload['meta']['offset']);
        $this->assertTrue($payload['meta']['has_more']);
        $this->assertCount(3, $payload['data']);
    }

    public function testSecondPageHasNoOverlapAndHasMoreFlagUpdates(): void
    {
        $p1 = JobsList::handle(true, 1, false, 4, 0);
        $p2 = JobsList::handle(true, 1, false, 4, 4);
        $ids1 = array_map(static fn($r) => (int) $r['id'], $p1['data']);
        $ids2 = array_map(static fn($r) => (int) $r['id'], $p2['data']);
        $this->assertSame([], array_intersect($ids1, $ids2));
        $this->assertFalse($p2['meta']['has_more']);
    }

    // Removed execute helper: using JobsList::handle directly to avoid emitting output during tests.

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
            error_text TEXT NULL,
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
        $now = $this->now();

        $userStmt = $pdo->prepare('INSERT INTO users (id, name, email, password_hash, role, created_at, updated_at) VALUES (:id,:name,:email,:password_hash,:role,:created_at,:updated_at)');
        $userStmt->execute([
            'id' => 1,
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'role' => 'admin',
            'created_at' => $now->format('Y-m-d\TH:i:s.uP'),
            'updated_at' => $now->format('Y-m-d\TH:i:s.uP'),
        ]);

        $pdo->exec("INSERT INTO providers (id, key, name) VALUES (1, 'webshare', 'Webshare')");

        $jobStmt = $pdo->prepare('INSERT INTO jobs (id,user_id,provider_id,external_id,title,source_url,category,status,progress,speed_bps,eta_seconds,priority,position,aria2_gid,tmp_path,final_path,error_text,deleted_at,created_at,updated_at) VALUES (:id,:user_id,:provider_id,:external_id,:title,:source_url,:category,:status,:progress,:speed_bps,:eta_seconds,:priority,:position,:aria2_gid,:tmp_path,:final_path,:error_text,:deleted_at,:created_at,:updated_at)');

        for ($i = 1; $i <= 6; $i++) {
            $created = $now->modify('+' . $i . ' minutes');
            $jobStmt->execute([
                'id' => $i,
                'user_id' => 1,
                'provider_id' => 1,
                'external_id' => 'ext-' . $i,
                'title' => 'Job ' . $i,
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
                'deleted_at' => null,
                'created_at' => $created->format('Y-m-d\TH:i:s.uP'),
                'updated_at' => $created->format('Y-m-d\TH:i:s.uP'),
            ]);
        }
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2025-03-01T00:00:00+00:00');
    }

    private function connection(): PDO
    {
        $this->assertInstanceOf(PDO::class, $this->pdo);
        return $this->pdo;
    }
}
