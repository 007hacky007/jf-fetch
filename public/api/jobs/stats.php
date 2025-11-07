<?php

declare(strict_types=1);

use App\Domain\Jobs;
use App\Infra\Auth;
use App\Infra\Config;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

Config::boot(dirname(__DIR__, 3) . '/config');
Auth::boot();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    return;
}

$identity = Auth::user();
$userId = $identity['id'] ?? 0;
$isAdmin = Auth::isAdmin();

try {
    $stats = Jobs::stats($isAdmin, $userId);
    echo json_encode(['data' => $stats]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to compute stats', 'details' => $exception->getMessage()]);
}