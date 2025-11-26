<?php

declare(strict_types=1);

namespace App\Tests\Integration;

require_once __DIR__ . '/../Support/Require.php';

use App\Domain\KraSk2BulkQueue;
use App\Infra\ProviderSecrets;
use App\Tests\TestCase;
use PDO;

final class KraSk2BulkQueueProcessingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDefaultConfig();
        $this->pdo = $this->useInMemoryDatabase();
        $this->createSchema($this->pdo);
        $this->seedFixtures($this->pdo);
        $this->loadScheduler();
    }

    public function testProcessKrask2BulkQueueCreatesJobsAndCounters(): void
    {
        $streamToken = $this->makeStreamToken('movie', 'vid-1', 'hash-1', 'https://cdn.example.test/video-1.mkv');

        $taskId = KraSk2BulkQueue::enqueue(1, [
            [
                'external_id' => $streamToken,
                'title' => 'Episode 1',
                'metadata' => ['season' => 2],
                'category' => 'Shows',
            ],
            [
                'external_id' => 'invalid-token',
                'title' => 'Broken entry',
            ],
        ]);

        processKrask2BulkQueue();

        $task = KraSk2BulkQueue::fetchById($taskId);
        $this->assertNotNull($task);
        $this->assertSame('completed', $task['status']);
        $this->assertSame(1, (int) $task['processed_items']);
        $this->assertSame(1, (int) $task['failed_items']);

        $jobs = $this->pdo->query('SELECT * FROM jobs ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertSame('Shows', $job['category']);
        $this->assertSame('queued', $job['status']);
        $this->assertSame(1, (int) $job['user_id']);
        $this->assertSame(1, (int) $job['provider_id']);
        $metadata = json_decode((string) $job['metadata_json'], true);
        $this->assertSame(['season' => 2], $metadata);

        $auditCount = (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'job.queued'")->fetchColumn();
        $this->assertSame(1, $auditCount);
        $notifications = (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
        $this->assertSame(1, $notifications);
    }

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT,
            email TEXT,
            password_hash TEXT,
            role TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $pdo->exec('CREATE TABLE providers (
            id INTEGER PRIMARY KEY,
            key TEXT,
            name TEXT,
            enabled INTEGER,
            config_json TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $pdo->exec('CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            provider_id INTEGER,
            external_id TEXT,
            title TEXT,
            status TEXT,
            priority INTEGER,
            position INTEGER,
            category TEXT,
            source_url TEXT,
            aria2_gid TEXT,
            progress INTEGER,
            speed_bps INTEGER,
            eta_seconds INTEGER,
            tmp_path TEXT,
            final_path TEXT,
            file_size_bytes INTEGER,
            error_text TEXT,
            metadata_json TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $pdo->exec('CREATE TABLE krask2_bulk_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            total_items INTEGER NOT NULL,
            processed_items INTEGER NOT NULL,
            failed_items INTEGER NOT NULL,
            payload_json TEXT NOT NULL,
            error_text TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            completed_at TEXT
        )');

        $pdo->exec('CREATE TABLE audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT,
            subject_type TEXT,
            subject_id INTEGER,
            payload_json TEXT,
            created_at TEXT
        )');

        $pdo->exec('CREATE TABLE notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            job_id INTEGER,
            type TEXT,
            payload_json TEXT,
            created_at TEXT
        )');
    }

    private function seedFixtures(PDO $pdo): void
    {
        $now = '2024-01-01T00:00:00.000000+00:00';
        $pdo->exec("INSERT INTO users (id, name, email, password_hash, role, created_at, updated_at) VALUES (1, 'Admin', 'admin@example.test', 'hash', 'admin', '$now', '$now')");

        $configJson = ProviderSecrets::encrypt([
            'manifest_url' => 'https://example.test/manifest.json',
            'user_agent' => 'UnitTest/1.0',
        ]);

        $stmt = $pdo->prepare('INSERT INTO providers (id, key, name, enabled, config_json, created_at, updated_at) VALUES (:id, :key, :name, :enabled, :config, :created, :updated)');
        $stmt->execute([
            'id' => 1,
            'key' => 'krask2',
            'name' => 'KraSk2',
            'enabled' => 1,
            'config' => $configJson,
            'created' => $now,
            'updated' => $now,
        ]);
    }

    private function loadScheduler(): void
    {
        if (!defined('APP_DISABLE_DAEMONS')) {
            define('APP_DISABLE_DAEMONS', true);
        }

        $root = dirname(__DIR__, 2);
        $handler = static function (int $severity, string $message): bool {
            if ($severity === E_WARNING && str_contains($message, "non-compound name 'PDO' has no effect")) {
                return true;
            }

            return false;
        };

        set_error_handler($handler);
        try {
            \jf_fetch_require_global($root . '/bin/scheduler.php');
        } finally {
            restore_error_handler();
        }
    }

    private function makeStreamToken(string $contentType, string $videoId, string $hash, string $url): string
    {
        $payload = [
            'v' => 1,
            't' => $contentType,
            'id' => $videoId,
            'hash' => $hash,
            'url' => $url,
            'title' => 'Stream title',
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return 'stream.' . $encoded;
    }
}
