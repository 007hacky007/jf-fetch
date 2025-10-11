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

$rows = Jobs::list($isAdmin, (int) $user['id'], $mineOnly);
$data = array_map(static fn (array $row): array => Jobs::format($row, $isAdmin), $rows);

Http::json(200, ['data' => $data]);
