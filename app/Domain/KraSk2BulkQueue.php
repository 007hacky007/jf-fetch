<?php

declare(strict_types=1);

namespace App\Domain;

use App\Infra\Db;
use App\Support\Clock;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Persists KraSk2 bulk queue requests so they can be expanded server-side.
 */
final class KraSk2BulkQueue
{
    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $options
     */
    public static function enqueue(int $userId, array $items, array $options = []): int
    {
        if ($items === []) {
            throw new RuntimeException('At least one KraSk2 item must be provided.');
        }

        $payload = json_encode([
            'items' => $items,
            'options' => $options,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new RuntimeException('Unable to encode KraSk2 queue payload.');
        }

        $timestamp = Clock::nowString();

        Db::run(
            'INSERT INTO krask2_bulk_queue (user_id, status, total_items, processed_items, failed_items, payload_json, created_at, updated_at)
             VALUES (:user_id, :status, :total_items, 0, 0, :payload, :created_at, :updated_at)',
            [
                'user_id' => $userId,
                'status' => 'pending',
                'total_items' => count($items),
                'payload' => $payload,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );

        return (int) Db::connection()->lastInsertId();
    }

    /**
     * Claims the oldest pending task for processing.
     *
     * @return array<string, mixed>|null
     */
    public static function claimPending(): ?array
    {
        return Db::transaction(static function () {
            $statement = Db::run(
                "SELECT * FROM krask2_bulk_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 1"
            );

            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }

            $taskId = (int) $row['id'];
            $timestamp = Clock::nowString();

            $updated = Db::run(
                "UPDATE krask2_bulk_queue SET status = 'processing', updated_at = :updated_at WHERE id = :id AND status = 'pending'",
                [
                    'updated_at' => $timestamp,
                    'id' => $taskId,
                ]
            );

            if ($updated->rowCount() === 0) {
                return null;
            }

            $row['status'] = 'processing';
            $row['updated_at'] = $timestamp;

            return $row;
        });
    }

    public static function markCompleted(int $taskId, int $processed, int $failed): void
    {
        $timestamp = Clock::nowString();
        Db::run(
            'UPDATE krask2_bulk_queue
             SET status = :status, processed_items = :processed, failed_items = :failed,
                 updated_at = :updated_at, completed_at = :completed_at, error_text = NULL
             WHERE id = :id',
            [
                'status' => 'completed',
                'processed' => $processed,
                'failed' => $failed,
                'updated_at' => $timestamp,
                'completed_at' => $timestamp,
                'id' => $taskId,
            ]
        );
    }

    public static function markFailed(int $taskId, int $processed, int $failed, string $error): void
    {
        $timestamp = Clock::nowString();
        Db::run(
            'UPDATE krask2_bulk_queue
             SET status = :status, processed_items = :processed, failed_items = :failed,
                 updated_at = :updated_at, error_text = :error
             WHERE id = :id',
            [
                'status' => 'failed',
                'processed' => $processed,
                'failed' => $failed,
                'updated_at' => $timestamp,
                'error' => mb_substr($error, 0, 500),
                'id' => $taskId,
            ]
        );
    }

    public static function updateCounters(int $taskId, int $processed, int $failed): void
    {
        $timestamp = Clock::nowString();
        Db::run(
            'UPDATE krask2_bulk_queue
             SET processed_items = :processed, failed_items = :failed, updated_at = :updated_at
             WHERE id = :id',
            [
                'processed' => $processed,
                'failed' => $failed,
                'updated_at' => $timestamp,
                'id' => $taskId,
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function fetchById(int $taskId): ?array
    {
        $statement = Db::run('SELECT * FROM krask2_bulk_queue WHERE id = :id LIMIT 1', ['id' => $taskId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, options: array<string, mixed>}
     */
    public static function decodePayload(array $task): array
    {
        $payload = $task['payload_json'] ?? '';
        if (!is_string($payload) || $payload === '') {
            return ['items' => [], 'options' => []];
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['items' => [], 'options' => []];
        }

        $items = [];
        if (isset($decoded['items']) && is_array($decoded['items'])) {
            $items = $decoded['items'];
        }

        $options = [];
        if (isset($decoded['options']) && is_array($decoded['options'])) {
            $options = $decoded['options'];
        }

        return [
            'items' => $items,
            'options' => $options,
        ];
    }
}
