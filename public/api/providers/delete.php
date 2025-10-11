<?php

declare(strict_types=1);

use App\Infra\Audit;
use App\Infra\Auth;
use App\Infra\Db;
use App\Infra\Http;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    Http::error(405, 'Method not allowed');
    exit;
}

try {
    Auth::boot();
    Auth::requireRole('admin');
} catch (RuntimeException $exception) {
    Http::error(403, $exception->getMessage());
    exit;
}

$actor = Auth::user();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    Http::error(400, 'Provider ID is required.');
    exit;
}

$existing = Db::run('SELECT id, key, name FROM providers WHERE id = :id LIMIT 1', ['id' => $id])->fetch();
if ($existing === false) {
    Http::error(404, 'Provider not found.');
    exit;
}

$deleted = Db::run('DELETE FROM providers WHERE id = :id', ['id' => $id])->rowCount();

if ($deleted === 0) {
    Http::error(500, 'Failed to delete provider.');
    exit;
}

$actorId = is_array($actor) && isset($actor['id']) ? (int) $actor['id'] : 0;
if ($actorId > 0) {
    Audit::record($actorId, 'provider.deleted', 'provider', $id, [
        'key' => (string) $existing['key'],
        'name' => (string) $existing['name'],
    ]);
}

Http::json(200, ['status' => 'deleted']);
