<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * Video provider contract for search and download URL resolution.
 */
interface VideoProvider
{
    /**
     * Searches provider catalog for the given query.
     *
     * @return array<int, array<string, mixed>> Normalized search results.
     */
    public function search(string $query, int $limit = 50): array;

    /**
     * Resolves a download URL (or URLs) from a provider-specific identifier.
     *
     * @return string|array<int, string>
     */
    public function resolveDownloadUrl(string $externalIdOrUrl): string|array;
}
