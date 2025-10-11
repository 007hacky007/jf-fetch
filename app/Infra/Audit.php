<?php

declare(strict_types=1);

namespace App\Infra;

use DateTimeImmutable;
use JsonException;
use Throwable;

/**
 * Provides small helpers for recording audit log entries.
 */
final class Audit
{
    /**
     * Persists an audit log entry for the given action and subject.
     *
     * @param int $userId Actor performing the action.
     * @param string $action Machine-friendly action name, e.g. "job.completed".
     * @param string $subjectType Type of entity being acted upon, e.g. "job".
     * @param int|null $subjectId Identifier of the subject, if any.
     * @param array<string, mixed> $payload Additional structured context for the action.
     */
    public static function record(int $userId, string $action, string $subjectType, ?int $subjectId, array $payload = []): void
    {
        if ($userId <= 0 || $action === '' || $subjectType === '') {
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
                'INSERT INTO audit_log (user_id, action, subject_type, subject_id, payload_json, created_at) VALUES (:user_id, :action, :subject_type, :subject_id, :payload_json, :created_at)',
                [
                    'user_id' => $userId,
                    'action' => $action,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'payload_json' => $payloadJson,
                    'created_at' => (new DateTimeImmutable())->format('c'),
                ]
            );
        } catch (Throwable $exception) {
            error_log('[audit] Failed to record entry: ' . $exception->getMessage());
        }
    }
}
