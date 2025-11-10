<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Jobs;
use App\Tests\TestCase;
use DateTimeImmutable;
use PDO;

final class JobsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDefaultConfig();
        $this->pdo = $this->useInMemoryDatabase();
        $this->createSchema();
        $this->seedFixtures();
    }

    public function testFormatIncludesOwnerAndDeletionMetadata(): void
    {
        $job = Jobs::fetchById(1);
        $this->assertNotNull($job);

        $formatted = Jobs::format($job, true);

        $this->assertSame('Alice Admin', $formatted['user_name']);
        $this->assertSame('alice@example.test', $formatted['user_email']);
        $this->assertArrayHasKey('user', $formatted);
        $this->assertSame('Alice Admin', $formatted['user']['name']);
        $this->assertSame('alice@example.test', $formatted['user']['email']);
    $this->assertSame('deleted', $formatted['status']);
    $this->assertArrayHasKey('deleted_at', $formatted);
    $this->assertSame('2024-01-02T12:00:00.000000+00:00', $formatted['deleted_at']);
    }

    public function testFormatOmitsDeletedAtWhenNull(): void
    {
        $job = Jobs::fetchById(2);
        $this->assertNotNull($job);

        $formatted = Jobs::format($job, false);

        $this->assertSame('queued', $formatted['status']);
        $this->assertArrayNotHasKey('deleted_at', $formatted);
        $this->assertSame('Bob Builder', $formatted['user_name']);
        $this->assertSame('bob@example.test', $formatted['user_email']);
        $this->assertArrayNotHasKey('user', $formatted, 'User subtree should be absent when includeUser=false');
    }

    public function testListOrdersByPriorityThenPosition(): void
    {
        $rows = Jobs::list(true, 1, false);
        $this->assertCount(2, $rows);

        $formatted = array_map(static fn (array $row): array => Jobs::format($row, true), $rows);

        $this->assertSame(2, $formatted[0]['id'], 'Queued job with lower priority should be first.');
        $this->assertSame(1, $formatted[1]['id']);
        $this->assertSame(10, $formatted[0]['priority']);
        $this->assertSame(200, $formatted[1]['priority']);
    }

    public function testFindExistingDownloadsMatchingReturnsCompletedTitles(): void
    {
        $pdo = $this->connection();
        $timestamp = new DateTimeImmutable('2024-02-01T00:00:00+00:00');

        $statement = $pdo->prepare('INSERT INTO jobs (
            id, user_id, provider_id, external_id, title, source_url, category, status, progress, speed_bps,
            eta_seconds, priority, position, aria2_gid, tmp_path, final_path, error_text, deleted_at,
            created_at, updated_at
        ) VALUES (
            :id, :user_id, :provider_id, :external_id, :title, :source_url, :category, :status, :progress,
            :speed_bps, :eta_seconds, :priority, :position, :aria2_gid, :tmp_path, :final_path, :error_text,
            :deleted_at, :created_at, :updated_at
        )');

        $statement->execute([
            'id' => 3,
            'user_id' => 1,
            'provider_id' => 1,
            'external_id' => 'ext-3',
            'title' => 'The Matrix (1999)',
            'source_url' => 'https://example.test/matrix',
            'category' => 'Movies',
            'status' => 'completed',
            'progress' => 100,
            'speed_bps' => null,
            'eta_seconds' => null,
            'priority' => 50,
            'position' => 3,
            'aria2_gid' => null,
            'tmp_path' => null,
            'final_path' => '/library/Movie/T/The Matrix (1999).mkv',
            'error_text' => null,
            'deleted_at' => null,
            'created_at' => $timestamp->format('Y-m-d\TH:i:s.uP'),
            'updated_at' => $timestamp->format('Y-m-d\TH:i:s.uP'),
        ]);

        $statement->execute([
            'id' => 4,
            'user_id' => 1,
            'provider_id' => 1,
            'external_id' => 'ext-4',
            'title' => 'The.Simpsons.S37E05.1080p.HEVC.x265-MeGusta.mkv',
            'source_url' => 'https://example.test/simpsons',
            'category' => 'TV',
            'status' => 'completed',
            'progress' => 100,
            'speed_bps' => null,
            'eta_seconds' => null,
            'priority' => 51,
            'position' => 4,
            'aria2_gid' => null,
            'tmp_path' => null,
            'final_path' => '/library/Series/The Simpsons/Season 37/The.Simpsons.S37E05.1080p.HEVC.x265-MeGusta.mkv',
            'error_text' => null,
            'deleted_at' => null,
            'created_at' => $timestamp->modify('+1 minute')->format('Y-m-d\TH:i:s.uP'),
            'updated_at' => $timestamp->modify('+1 minute')->format('Y-m-d\TH:i:s.uP'),
        ]);

        $matches = Jobs::findExistingDownloadsMatching('matrix');
        $this->assertSame(['The Matrix (1999)'], $matches);

        $simpsonsMatches = Jobs::findExistingDownloadsMatching('The Simpsons');
        $this->assertSame(['The.Simpsons.S37E05.1080p.HEVC.x265-MeGusta.mkv'], $simpsonsMatches);
        $this->assertSame(['The.Simpsons.S37E05.1080p.HEVC.x265-MeGusta.mkv'], Jobs::findExistingDownloadsMatching('simpsons'));

        $this->assertSame([], Jobs::findExistingDownloadsMatching('Second'));
        $this->assertSame([], Jobs::findExistingDownloadsMatching(''));

        $pdo->exec('DELETE FROM jobs WHERE id IN (3, 4)');
    }

    private function createSchema(): void
    {
        $pdo = $this->connection();

        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL
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

        $pdo->exec("INSERT INTO users (id, name, email) VALUES (1, 'Alice Admin', 'alice@example.test')");
        $pdo->exec("INSERT INTO users (id, name, email) VALUES (2, 'Bob Builder', 'bob@example.test')");

        $pdo->exec("INSERT INTO providers (id, key, name) VALUES (1, 'webshare', 'Webshare')");

        $createdAt = new DateTimeImmutable('2024-01-01T00:00:00+00:00');
        $deletedAt = new DateTimeImmutable('2024-01-02T12:00:00+00:00');

        $statement = $pdo->prepare('INSERT INTO jobs (
            id, user_id, provider_id, external_id, title, source_url, category, status, progress, speed_bps,
            eta_seconds, priority, position, aria2_gid, tmp_path, final_path, error_text, deleted_at,
            created_at, updated_at
        ) VALUES (
            :id, :user_id, :provider_id, :external_id, :title, :source_url, :category, :status, :progress,
            :speed_bps, :eta_seconds, :priority, :position, :aria2_gid, :tmp_path, :final_path, :error_text,
            :deleted_at, :created_at, :updated_at
        )');

        $statement->execute([
            'id' => 1,
            'user_id' => 1,
            'provider_id' => 1,
            'external_id' => 'ext-1',
            'title' => 'Example Download',
            'source_url' => 'https://example.test/file',
            'category' => 'Movies',
            'status' => 'deleted',
            'progress' => 0,
            'speed_bps' => null,
            'eta_seconds' => null,
            'priority' => 200,
            'position' => 2,
            'aria2_gid' => null,
            'tmp_path' => null,
            'final_path' => null,
            'error_text' => 'File deletion requested by administrator.',
            'deleted_at' => $deletedAt->format('Y-m-d\TH:i:s.uP'),
            'created_at' => $createdAt->format('Y-m-d\TH:i:s.uP'),
            'updated_at' => $deletedAt->format('Y-m-d\TH:i:s.uP'),
        ]);

        $statement = $pdo->prepare('INSERT INTO jobs (
            id, user_id, provider_id, external_id, title, source_url, category, status, progress, speed_bps,
            eta_seconds, priority, position, aria2_gid, tmp_path, final_path, error_text, deleted_at,
            created_at, updated_at
        ) VALUES (
            :id, :user_id, :provider_id, :external_id, :title, :source_url, :category, :status, :progress,
            :speed_bps, :eta_seconds, :priority, :position, :aria2_gid, :tmp_path, :final_path, :error_text,
            :deleted_at, :created_at, :updated_at
        )');

        $statement->execute([
            'id' => 2,
            'user_id' => 2,
            'provider_id' => 1,
            'external_id' => 'ext-2',
            'title' => 'Second Download',
            'source_url' => 'https://example.test/file2',
            'category' => 'Movies',
            'status' => 'queued',
            'progress' => 0,
            'speed_bps' => null,
            'eta_seconds' => null,
            'priority' => 10,
            'position' => 1,
            'aria2_gid' => null,
            'tmp_path' => null,
            'final_path' => '/library/Second Download.mkv',
            'error_text' => null,
            'deleted_at' => null,
            'created_at' => $createdAt->format('Y-m-d\TH:i:s.uP'),
            'updated_at' => $createdAt->format('Y-m-d\TH:i:s.uP'),
        ]);
    }

    private function connection(): \PDO
    {
        $this->assertInstanceOf(\PDO::class, $this->pdo);

        return $this->pdo;
    }
}
