<?php

declare(strict_types=1);

use App\Domain\Users;
use App\Infra\Audit;
use App\Infra\Auth;
use App\Infra\Events;
use App\Infra\Http;

header('Content-Type: application/json');

$allowedMethods = ['GET', 'POST', 'PATCH', 'DELETE'];
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $allowedMethods, true)) {
    Http::error(405, 'Method not allowed');
    exit;
}

try {
    Auth::boot();
    Auth::requireRole('admin');
} catch (RuntimeException $exception) {
    $status = Auth::check() ? 403 : 401;
    Http::error($status, $exception->getMessage());
    exit;
}

$user = Auth::user();
if (!is_array($user) || !isset($user['id'])) {
    Http::error(401, 'Authentication required.');
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $users = Users::all();
        Http::json(200, ['data' => $users]);

        return;
    }

    if ($method === 'POST') {
        $payload = Http::readJsonBody();
        $created = Users::create($payload);

        Audit::record((int) $user['id'], 'user.created', 'user', (int) $created['id']);

        Http::json(201, ['data' => $created]);

        return;
    }

    if ($method === 'PATCH') {
        $id = requireUserId();
        $payload = Http::readJsonBody();
        $updated = Users::update($id, $payload);

        Audit::record((int) $user['id'], 'user.updated', 'user', $id);

        Http::json(200, ['data' => $updated]);

        return;
    }

    if ($method === 'DELETE') {
        $id = requireUserId();
        Users::delete($id, (int) $user['id']);

        Audit::record((int) $user['id'], 'user.deleted', 'user', $id);

        Events::notify((int) $user['id'], null, 'user.deleted', ['user_id' => $id]);

        Http::json(200, ['status' => 'deleted']);

        return;
    }
} catch (RuntimeException $exception) {
    Http::error(422, $exception->getMessage());

    return;
} catch (Throwable $exception) {
    Http::error(500, 'Unexpected server error.', ['detail' => $exception->getMessage()]);

    return;
}

Http::error(405, 'Method not allowed');

/**
 * Resolves the user ID from the query string.
 */
function requireUserId(): int
{
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        throw new RuntimeException('User ID is required.');
    }

    return $id;
}
