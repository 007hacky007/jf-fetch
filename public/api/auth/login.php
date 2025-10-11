<?php

declare(strict_types=1);

use App\Infra\Audit;
use App\Infra\Auth;

header('Content-Type: application/json');

// Handle user login via JSON payload containing email and password.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '{}';

try {
    $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload', 'detail' => $exception->getMessage()]);
    exit;
}

$email = isset($payload['email']) ? trim((string) $payload['email']) : '';
$password = isset($payload['password']) ? (string) $payload['password'] : '';

if ($email === '' || $password === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Email and password are required']);
    exit;
}

try {
    $user = Auth::attempt($email, $password);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'Authentication unavailable', 'detail' => $exception->getMessage()]);
    exit;
}

if ($user === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
    exit;
}

Audit::record((int) $user['id'], 'auth.login', 'user', (int) $user['id']);

http_response_code(200);
echo json_encode(['user' => $user]);
