<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Infra\Auth;
use App\Infra\Db;
use App\Support\Clock;
use App\Tests\TestCase;
use PDO;

final class JobsStatsEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDefaultConfig();
        $pdo = $this->useInMemoryDatabase();
        $this->migrate($pdo);
        $this->seedUsers($pdo);
        Auth::boot();
        // Authenticate as admin (user id 1)
        $_SESSION['auth.user'] = [
            'id' => 1,
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'role' => 'admin',
            'created_at' => Clock::nowString(),
            'updated_at' => Clock::nowString(),
            'last_login_at' => Clock::nowString(),
        ];
    }

    public function testStatsEndpointReturnsAggregates(): void
    {
        $now = Clock::nowString();
        // Insert providers
        Db::run("INSERT INTO providers (id, key, name, enabled, config_json, created_at, updated_at) VALUES (1,'webshare','Webshare',1,'{}',:now,:now)", ['now' => $now]);
        // Insert jobs with various statuses
        $jobs = [
            ['id' => 10, 'status' => 'queued'],
            ['id' => 11, 'status' => 'starting'],
            ['id' => 12, 'status' => 'downloading'],
            ['id' => 13, 'status' => 'paused'],
            ['id' => 14, 'status' => 'canceled'],
            ['id' => 15, 'status' => 'failed'],
            ['id' => 16, 'status' => 'deleted'],
        ];

        foreach ($jobs as $job) {
            Db::run(
                'INSERT INTO jobs (id,user_id,provider_id,external_id,title,source_url,status,progress,priority,position,metadata_json,created_at,updated_at) VALUES (:id,1,1,:ext,:title,:src,:status,0,0,:pos,:metadata,:now,:now)',
                [
                    'id' => $job['id'],
                    'title' => 'Job ' . $job['id'],
                    'status' => $job['status'],
                    'pos' => $job['id'],
                    'ext' => 'ext-' . $job['id'],
                    'src' => 'http://example.com/video-' . $job['id'],
                    'metadata' => null,
                    'now' => $now,
                ]
            );
        }

        // Completed jobs with fake files
        $completedIds = [20, 21];
        foreach ($completedIds as $cid) {
            $filePath = $this->configDir . '/file_' . $cid . '.bin';
            file_put_contents($filePath, str_repeat('A', $cid === 20 ? 1500 : 4096)); // 1500 + 4096 bytes
            Db::run(
                'INSERT INTO jobs (id,user_id,provider_id,external_id,title,source_url,status,progress,priority,position,final_path,metadata_json,created_at,updated_at) VALUES (:id,1,1,:ext,:title,:src,\'completed\',100,0,:pos,:path,:metadata,:created,:updated)',
                [
                    'id' => $cid,
                    'title' => 'Completed ' . $cid,
                    'pos' => $cid,
                    'ext' => 'ext-' . $cid,
                    'src' => 'http://example.com/video-' . $cid,
                    'path' => $filePath,
                    'metadata' => null,
                    'created' => $now,
                    'updated' => $now,
                ]
            );
        }

        // Invoke endpoint
        $script = dirname(__DIR__, 2) . '/public/api/jobs/stats.php';
        ob_start();
        require $script;
        $json = ob_get_clean();

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'Endpoint should return JSON array');
        $data = $decoded['data'] ?? null;
        $this->assertIsArray($data, 'Stats data missing');

        $this->assertSame(9, $data['total_jobs']); // 7 assorted + 2 completed
        $this->assertSame(2, $data['completed_jobs']);
        $this->assertSame(2, $data['active_jobs']); // starting + downloading
        $this->assertSame(1, $data['queued_jobs']);
        $this->assertSame(1, $data['paused_jobs']);
        $this->assertSame(1, $data['canceled_jobs']);
        $this->assertSame(1, $data['failed_jobs']);
        $this->assertSame(1, $data['deleted_jobs']);
        $this->assertSame(1, $data['distinct_users']);

        $expectedBytes = 1500 + 4096;
        $this->assertSame($expectedBytes, $data['total_bytes_downloaded']);
        $this->assertSame(0, $data['total_download_duration_seconds']); // same created/updated timestamps
        $this->assertSame(0, $data['avg_download_duration_seconds']);
    }

    private function migrate(PDO $pdo): void
    {
        $migrations = [
            '/../../database/migrations/0001_initial_schema.sql',
            '/../../database/migrations/0004_add_deleted_status_to_jobs.sql',
            '/../../database/migrations/0005_add_metadata_json_to_jobs.sql',
        ];

        foreach ($migrations as $relativePath) {
            $sql = file_get_contents(__DIR__ . $relativePath);
            if (!is_string($sql)) {
                $this->fail(sprintf('Failed to read migration SQL from %s', $relativePath));
            }
            $pdo->exec($sql);
        }
    }

    private function seedUsers(PDO $pdo): void
    {
        $now = Clock::nowString();
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (id,name,email,password_hash,role,created_at,updated_at) VALUES (1,'Admin','admin@example.com','$hash','admin','$now','$now')");
    }
}
