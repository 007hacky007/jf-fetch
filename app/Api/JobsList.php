<?php

declare(strict_types=1);

namespace App\Api;

use App\Domain\Jobs;
use App\Infra\ProviderBackoff;

final class JobsList
{
    /**
     * Core handler logic for jobs list (paged or full) returning payload array.
     * Designed so tests can invoke without emitting output headers.
     *
     * @param bool $isAdmin  Whether requesting user is admin.
     * @param int  $userId   Authenticated user id.
     * @param bool $mineOnly Filter to only user's jobs when true.
     * @param int|null $limit Optional page size (null for full list).
     * @param int|null $offset Optional page offset.
     * @return array<string, mixed> Response payload identical to API output.
     */
    public static function handle(bool $isAdmin, int $userId, bool $mineOnly, ?int $limit, ?int $offset): array
    {
        if ($limit !== null) {
            $limit = max(1, min(100, $limit));
            $offset = $offset !== null ? max(0, $offset) : 0;
            $paged = Jobs::listPaged($isAdmin, $userId, $mineOnly, $limit, $offset);
            $data = array_map(static fn(array $row): array => Jobs::format($row, $isAdmin), $paged['rows']);
            $total = $paged['total'];
            $hasMore = $offset + $limit < $total;
            $meta = [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => $hasMore,
                'provider_backoff' => ProviderBackoff::active(),
            ];
            return [
                'data' => $data,
                'meta' => $meta,
            ];
        }
        $rows = Jobs::list($isAdmin, $userId, $mineOnly);
        $data = array_map(static fn(array $row): array => Jobs::format($row, $isAdmin), $rows);
        return [
            'data' => $data,
            'meta' => [
                'provider_backoff' => ProviderBackoff::active(),
            ],
        ];
    }
}
