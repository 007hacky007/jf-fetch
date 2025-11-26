<?php

declare(strict_types=1);

namespace App\Domain;

use App\Infra\Db;
use App\Support\Clock;
use RuntimeException;
use Throwable;

final class JobQueueWriter
{
    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $currentUser
     * @param array<string, array<string, mixed>> $providers
     * @return array<int> Inserted job IDs
     */
    public static function insertJobs(array $items, array $currentUser, array $providers, ?string $globalCategory = null): array
    {
        if ($items === []) {
            return [];
        }

        $timestamp = Clock::nowString();
        $position = Jobs::nextPosition();
        $createdJobs = [];

        Db::transaction(static function () use ($items, $currentUser, $providers, $globalCategory, $timestamp, &$position, &$createdJobs): void {
            foreach ($items as $item) {
                $providerKey = trim((string) ($item['provider'] ?? ''));
                if ($providerKey === '' || !isset($providers[$providerKey])) {
                    throw new RuntimeException(sprintf('Provider "%s" is not available.', $providerKey));
                }
                $provider = $providers[$providerKey];

                $externalId = isset($item['external_id']) ? trim((string) $item['external_id']) : '';
                if ($externalId === '') {
                    throw new RuntimeException('Queue item is missing external identifier.');
                }

                $title = isset($item['title']) ? trim((string) $item['title']) : '';
                if ($title === '') {
                    $title = sprintf('[%s] %s', strtoupper($providerKey), $externalId);
                }

                $category = $globalCategory;
                if (isset($item['category'])) {
                    $candidate = trim((string) $item['category']);
                    if ($candidate !== '') {
                        $category = $candidate;
                    }
                }

                $priority = isset($item['priority']) ? (int) $item['priority'] : 100;
                $priority = max(0, min(1000, $priority));

                $metadata = self::normalizeJobMetadata($item['metadata'] ?? null);
                $metadataJson = $metadata !== null
                    ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null;

                if ($metadataJson !== null && strlen($metadataJson) > 65535) {
                    $metadataJson = substr($metadataJson, 0, 65535);
                }

                Db::run(
                    'INSERT INTO jobs (user_id, provider_id, external_id, title, source_url, category, status, progress, speed_bps, eta_seconds, priority, position, aria2_gid, tmp_path, final_path, error_text, metadata_json, created_at, updated_at)
                     VALUES (:user_id, :provider_id, :external_id, :title, :source_url, :category, :status, 0, NULL, NULL, :priority, :position, NULL, NULL, NULL, NULL, :metadata_json, :created_at, :updated_at)',
                    [
                        'user_id' => (int) $currentUser['id'],
                        'provider_id' => (int) $provider['id'],
                        'external_id' => $externalId,
                        'title' => $title,
                        'source_url' => '',
                        'category' => $category,
                        'status' => 'queued',
                        'priority' => $priority,
                        'position' => $position,
                        'metadata_json' => $metadataJson,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]
                );

                $jobId = (int) Db::connection()->lastInsertId();
                $position++;

                $createdJobs[] = $jobId;
            }
        });

        return $createdJobs;
    }

    public static function normalizeJobMetadata(mixed $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        if ($metadata instanceof \JsonSerializable) {
            $metadata = $metadata->jsonSerialize();
        }

        if ($metadata instanceof \stdClass) {
            $metadata = (array) $metadata;
        }

        if (!is_array($metadata)) {
            return null;
        }

        $normalized = self::normalizeJobMetadataValue($metadata, 0);
        return is_array($normalized) && $normalized !== [] ? $normalized : null;
    }

    private static function normalizeJobMetadataValue(mixed $value, int $depth): mixed
    {
        if ($depth >= 6) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            if (function_exists('mb_substr')) {
                $trimmed = mb_substr($trimmed, 0, 2000);
            } else {
                $trimmed = substr($trimmed, 0, 2000);
            }

            return $trimmed;
        }

        if ($value instanceof \JsonSerializable) {
            return self::normalizeJobMetadataValue($value->jsonSerialize(), $depth + 1);
        }

        if ($value instanceof \stdClass) {
            return self::normalizeJobMetadataValue((array) $value, $depth + 1);
        }

        if (!is_array($value)) {
            return null;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            $result = [];
            foreach ($value as $entry) {
                if (count($result) >= 50) {
                    break;
                }
                $normalized = self::normalizeJobMetadataValue($entry, $depth + 1);
                if ($normalized === null) {
                    continue;
                }
                $result[] = $normalized;
            }

            return $result === [] ? null : $result;
        }

        $result = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (count($result) >= 50) {
                break;
            }

            $normalized = self::normalizeJobMetadataValue($entry, $depth + 1);
            if ($normalized === null) {
                continue;
            }

            $result[$key] = $normalized;
        }

        return $result === [] ? null : $result;
    }
}
