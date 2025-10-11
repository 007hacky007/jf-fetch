<?php

declare(strict_types=1);

use App\Infra\Auth;

header('Content-Type: application/json');

try {
    Auth::boot();
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'Authentication unavailable', 'detail' => $exception->getMessage()]);
    exit;
}

$user = Auth::user();

if ($user === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

echo json_encode(['user' => $user]);
