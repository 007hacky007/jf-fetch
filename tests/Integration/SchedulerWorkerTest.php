<?php

declare(strict_types=1);

namespace App\Tests\Integration;

require_once __DIR__ . '/../Support/Require.php';

use App\Infra\Config;
use App\Infra\ProviderPause;
use App\Providers\KraSkApiException;
use App\Tests\TestCase;
use PDO;

final class SchedulerWorkerTest extends TestCase
{
    private string $downloadsDir;

    private string $libraryDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->downloadsDir = $this->configDir . '/downloads';
        $this->libraryDir = $this->configDir . '/library';
        $this->bootDefaultConfig([
            'paths' => [
                'downloads' => $this->downloadsDir,
                'library' => $this->libraryDir,
            ],
        ]);

        $pdo = $this->useInMemoryDatabase();
        $this->createSchema($pdo);
        $this->seedData($pdo);
        $this->loadDaemons();
    }

    public function testClaimAndCompletionPipeline(): void
    {
        $job = claimNextJob();
        $this->assertNotNull($job);
        $this->assertSame('starting', $job['status']);

        $downloadPath = $this->downloadsDir . '/incoming-file.mkv';
        file_put_contents($downloadPath, 'video-bytes');

        $now = '2024-01-02T00:00:00.000000+00:00';
        $stmt = $this->pdo->prepare('UPDATE jobs SET status = :status, aria2_gid = :gid, tmp_path = :tmp, category = :category, title = :title, updated_at = :updated WHERE id = :id');
        $stmt->execute([
            'status' => 'downloading',
            'gid' => 'GID123',
            'tmp' => $downloadPath,
            'category' => 'Movies',
            'title' => 'Test Movie (2024)',
            'updated' => $now,
            'id' => 1,
        ]);

        $jobRow = $this->pdo->query('SELECT * FROM jobs WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($jobRow);

        $status = [
            'status' => 'complete',
            'files' => [
                ['path' => $downloadPath],
            ],
        ];

        handleCompletion($jobRow, $status);

        $finalRow = $this->pdo->query('SELECT * FROM jobs WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($finalRow);
        $this->assertSame('completed', $finalRow['status']);
        $this->assertNotNull($finalRow['final_path']);
        $this->assertFileExists($finalRow['final_path']);
        $this->assertFileDoesNotExist($downloadPath);
        $this->assertStringContainsString('/Movies/Test Movie (2024)/Test Movie (2024).mkv', $finalRow['final_path']);

        $auditCount = (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'job.completed'")->fetchColumn();
        $this->assertSame(1, $auditCount);

        $notificationCount = (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
        $this->assertSame(1, $notificationCount);
    }

    public function testClaimSkipsPausedProviders(): void
    {
        ProviderPause::set('webshare', [
            'provider_id' => 1,
            'provider_label' => 'Webshare',
            'paused_by' => 'Admin',
            'paused_by_id' => 1,
        ]);

        $job = claimNextJob(ProviderPause::providerIds());
        $this->assertNull($job);
    }

    public function testKraskaObjectNotFoundDetectionFromMessage(): void
    {
        $this->assertFalse(\isKraskaObjectNotFound(new \RuntimeException('Some other error')));

        $exception = new \RuntimeException('Failed to enqueue job: Kra.sk API error: Code 1210: object not found');
        $this->assertTrue(\isKraskaObjectNotFound($exception));

        $context = \kraskaExceptionContext($exception);
        $this->assertNull($context['status_code']);
        $this->assertNull($context['endpoint']);
        $this->assertIsString($context['response_preview']);
        $this->assertStringContainsString('1210', (string) $context['response_preview']);
    }

    public function testKraskaObjectNotFoundDetectionFromResponseBody(): void
    {
        $apiException = new KraSkApiException(
            422,
            '/api/file/download',
            ['data' => ['ident' => 'kra-999']],
            'https://api.kra.sk/api/file/download',
            '{"error":1210,"message":"object not found"}'
        );

        $wrapper = new \RuntimeException('Wrapper', 0, $apiException);

        $this->assertTrue(\isKraskaObjectNotFound($wrapper));

        $context = \kraskaExceptionContext($wrapper);
        $this->assertSame(422, $context['status_code']);
        $this->assertSame('/api/file/download', $context['endpoint']);
        $this->assertIsString($context['response_preview']);
        $this->assertStringContainsString('1210', (string) $context['response_preview']);
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
            id INTEGER PRIMARY KEY,
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

        $pdo->exec('CREATE TABLE settings (
            key TEXT PRIMARY KEY,
            value TEXT,
            type TEXT,
            updated_at TEXT
        )');
    }

    private function seedData(PDO $pdo): void
    {
        $now = '2024-01-01T00:00:00.000000+00:00';

        $pdo->exec("INSERT INTO users (id, name, email, password_hash, role, created_at, updated_at) VALUES (1, 'Admin', 'admin@example.com', 'hash', 'admin', '$now', '$now')");
        $pdo->exec("INSERT INTO providers (id, key, name, enabled, config_json, created_at, updated_at) VALUES (1, 'webshare', 'Webshare', 1, '', '$now', '$now')");

        $pdo->exec("INSERT INTO jobs (id, user_id, provider_id, external_id, title, status, priority, position, category, source_url, aria2_gid, progress, speed_bps, eta_seconds, tmp_path, final_path, error_text, metadata_json, created_at, updated_at)
            VALUES (1, 1, 1, 'ext-1', 'Seed Job', 'queued', 100, 1, 'Movies', '', NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, '$now', '$now')");
    }

    private function loadDaemons(): void
    {
        if (!defined('APP_DISABLE_DAEMONS')) {
            define('APP_DISABLE_DAEMONS', true);
        }

        $root = dirname(__DIR__, 2);
        \jf_fetch_require_global($root . '/bin/scheduler.php');
        \jf_fetch_require_global($root . '/bin/worker.php');
    }
}
