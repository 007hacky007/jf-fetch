<?php

declare(strict_types=1);

use App\Domain\Jobs;
use App\Infra\Auth;
use App\Infra\Http;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Http::error(405, 'Method not allowed');
    exit;
}

try {
    Auth::boot();
    Auth::requireUser();
} catch (RuntimeException $exception) {
    Http::error(401, $exception->getMessage());
    exit;
}

$user = Auth::user();
if ($user === null) {
    Http::error(401, 'Authentication required.');
    exit;
}

$isAdmin = Auth::isAdmin();
$mineOnly = isset($_GET['mine']) && $_GET['mine'] === '1';

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : null;
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : null;

// Sanitize pagination values if provided
if ($limit !== null) {
    $limit = max(1, min(100, $limit)); // hard cap at 100
    $offset = $offset !== null ? max(0, $offset) : 0;
}

if ($limit !== null) {
    // Paged variant
    $paged = Jobs::listPaged($isAdmin, (int) $user['id'], $mineOnly, $limit, $offset);
    $data = array_map(static fn (array $row): array => Jobs::format($row, $isAdmin), $paged['rows']);
    $total = $paged['total'];
    $hasMore = $offset + $limit < $total;
    Http::json(200, [
        'data' => $data,
        'meta' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
        ],
    ]);
    exit;
}

// Legacy full list behavior (no pagination requested)
$rows = Jobs::list($isAdmin, (int) $user['id'], $mineOnly);
$data = array_map(static fn (array $row): array => Jobs::format($row, $isAdmin), $rows);
Http::json(200, ['data' => $data]);
