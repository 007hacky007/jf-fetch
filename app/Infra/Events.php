<?php

declare(strict_types=1);

namespace App\Infra;

use DateTimeImmutable;
use JsonException;
use Throwable;

/**
 * Centralised helpers for emitting application events and notifications.
 */
final class Events
{
    /**
     * Records a notification-style event for a user (optionally tied to a job).
     * The UI polls the notifications table to build toasts and history.
     *
     * @param array<string, mixed> $payload Additional structured context.
     */
    public static function notify(int $userId, ?int $jobId, string $type, array $payload = []): void
    {
        if ($userId <= 0 || $type === '') {
            return;
        }

        try {
            $payloadJson = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            $payloadJson = '{}';
        }

        try {
            Db::run(
                'INSERT INTO notifications (user_id, job_id, type, payload_json, created_at) VALUES (:user_id, :job_id, :type, :payload, :created_at)',
                [
                    'user_id' => $userId,
                    'job_id' => $jobId,
                    'type' => $type,
                    'payload' => $payloadJson,
                    'created_at' => (new DateTimeImmutable())->format('c'),
                ]
            );
        } catch (Throwable $exception) {
            error_log('[events] Failed to persist notification: ' . $exception->getMessage());
        }
    }
}
