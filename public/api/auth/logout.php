<?php

declare(strict_types=1);

use App\Infra\Audit;
use App\Infra\Auth;

header('Content-Type: application/json');

// Handle session logout requests for the currently authenticated user.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    Auth::boot();
    $user = Auth::user();
    Auth::logout();
    if (is_array($user) && isset($user['id'])) {
        Audit::record((int) $user['id'], 'auth.logout', 'user', (int) $user['id']);
    }
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to logout', 'detail' => $exception->getMessage()]);
    exit;
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
