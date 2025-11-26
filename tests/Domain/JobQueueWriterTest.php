<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\JobQueueWriter;
use App\Tests\TestCase;
use PDO;
use RuntimeException;

final class JobQueueWriterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDefaultConfig();
        $this->pdo = $this->useInMemoryDatabase();
        $this->createSchema($this->pdo);
        $this->seedExistingJob($this->pdo);
    }

    public function testInsertJobsPersistsMetadataAndCategories(): void
    {
        $currentUser = ['id' => 42];
        $providers = [
            'krask2' => [
                'id' => 7,
                'key' => 'krask2',
                'name' => 'KraSk2',
            ],
        ];

        $jobs = [
            [
                'provider' => 'krask2',
                'external_id' => 'stream.abc',
                'title' => 'Episode 1',
                'category' => 'Movies',
                'priority' => 250,
                'metadata' => [
                    'season' => 1,
                    'episode' => '  ',
                    'details' => (object) ['lang' => 'CZ'],
                    'extra' => ['keep', '', null, 'drop'],
                ],
            ],
            [
                'provider' => 'krask2',
                'external_id' => 'stream.xyz',
                'title' => '',
                'metadata' => [
                    'list' => ['alpha', '', 'beta'],
                ],
            ],
        ];

        $inserted = JobQueueWriter::insertJobs($jobs, $currentUser, $providers, 'Shows');

        $this->assertCount(2, $inserted);
        $this->assertSame([2, 3], $inserted);

        $rows = $this->pdo->query('SELECT * FROM jobs ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $rows);

        $first = $rows[1];
        $this->assertSame('Movies', $first['category']);
        $this->assertSame(250, (int) $first['priority']);
        $this->assertSame(6, (int) $first['position']);
        $metadata = json_decode((string) $first['metadata_json'], true);
        $this->assertSame([
            'season' => 1,
            'details' => ['lang' => 'CZ'],
            'extra' => ['keep', 'drop'],
        ], $metadata);

        $second = $rows[2];
        $this->assertSame('Shows', $second['category']);
        $this->assertSame(7, (int) $second['position']);
        $this->assertSame('[KRASK2] stream.xyz', $second['title']);
        $metadata = json_decode((string) $second['metadata_json'], true);
        $this->assertSame([
            'list' => ['alpha', 'beta'],
        ], $metadata);
    }

    public function testInsertJobsRequiresKnownProvider(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Provider "unknown" is not available.');

        JobQueueWriter::insertJobs([
            [
                'provider' => 'unknown',
                'external_id' => 'stream.fail',
                'title' => 'Broken',
            ],
        ], ['id' => 1], [], null);
    }

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            provider_id INTEGER NOT NULL,
            external_id TEXT NOT NULL,
            title TEXT NOT NULL,
            source_url TEXT,
            category TEXT,
            status TEXT,
            progress INTEGER,
            speed_bps INTEGER,
            eta_seconds INTEGER,
            priority INTEGER,
            position INTEGER,
            aria2_gid TEXT,
            tmp_path TEXT,
            final_path TEXT,
            error_text TEXT,
            metadata_json TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
    }

    private function seedExistingJob(PDO $pdo): void
    {
        $pdo->exec("INSERT INTO jobs (id, user_id, provider_id, external_id, title, source_url, category, status, progress, speed_bps, eta_seconds, priority, position, aria2_gid, tmp_path, final_path, error_text, metadata_json, created_at, updated_at)
            VALUES (1, 1, 1, 'ext-1', 'Existing', '', 'Movies', 'queued', 0, NULL, NULL, 100, 5, NULL, NULL, NULL, NULL, NULL, '2024-01-01T00:00:00.000000+00:00', '2024-01-01T00:00:00.000000+00:00')");
    }
}
