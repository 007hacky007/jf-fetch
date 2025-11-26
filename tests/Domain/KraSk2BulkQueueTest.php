<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\KraSk2BulkQueue;
use App\Tests\TestCase;
use PDO;

final class KraSk2BulkQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDefaultConfig();
        $this->pdo = $this->useInMemoryDatabase();
        $this->createSchema($this->pdo);
    }

    public function testEnqueueClaimAndCompletionLifecycle(): void
    {
        $items = [
            ['external_id' => 'stream.one', 'title' => 'One'],
            ['external_id' => 'stream.two', 'title' => 'Two'],
        ];
        $options = ['category' => 'Shows'];

        $taskId = KraSk2BulkQueue::enqueue(9, $items, $options);
        $this->assertSame(1, $taskId);

        $pending = KraSk2BulkQueue::fetchById($taskId);
        $this->assertNotNull($pending);
        $this->assertSame('pending', $pending['status']);
        $this->assertSame(2, (int) $pending['total_items']);

        $claimed = KraSk2BulkQueue::claimPending();
        $this->assertNotNull($claimed);
        $this->assertSame('processing', $claimed['status']);

        $decoded = KraSk2BulkQueue::decodePayload($claimed);
        $this->assertSame($items, $decoded['items']);
        $this->assertSame($options, $decoded['options']);

        KraSk2BulkQueue::markCompleted($taskId, 2, 0);
        $completed = KraSk2BulkQueue::fetchById($taskId);
        $this->assertNotNull($completed);
        $this->assertSame('completed', $completed['status']);
        $this->assertSame(2, (int) $completed['processed_items']);
        $this->assertSame(0, (int) $completed['failed_items']);
        $this->assertNotNull($completed['completed_at']);
    }

    public function testMarkFailedPersistsErrorAndCounters(): void
    {
        $taskId = KraSk2BulkQueue::enqueue(3, [
            ['external_id' => 'stream.fail', 'title' => 'Broken'],
        ]);

        KraSk2BulkQueue::markFailed($taskId, 1, 2, 'Network unavailable.');

        $failed = KraSk2BulkQueue::fetchById($taskId);
        $this->assertNotNull($failed);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame(1, (int) $failed['processed_items']);
        $this->assertSame(2, (int) $failed['failed_items']);
        $this->assertSame('Network unavailable.', $failed['error_text']);
    }

    private function createSchema(PDO $pdo): void
    {
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
    }
}
