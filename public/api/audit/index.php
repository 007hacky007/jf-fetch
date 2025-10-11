<?php

declare(strict_types=1);

use App\Infra\Auth;
use App\Infra\Db;
use App\Infra\Http;

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
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

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
$limit = max(1, min(200, $limit));

$beforeId = isset($_GET['before']) ? (int) $_GET['before'] : 0;

$params = [];
$where = '';
if ($beforeId > 0) {
    $where = 'WHERE audit_log.id < :before_id';
    $params['before_id'] = $beforeId;
}

$sql = "SELECT audit_log.*, users.name AS user_name, users.email AS user_email
        FROM audit_log
        INNER JOIN users ON users.id = audit_log.user_id
        $where
        ORDER BY audit_log.id DESC
        LIMIT :limit";

$params['limit'] = $limit;

$stmt = Db::connection()->prepare($sql);
if ($stmt === false) {
    Http::error(500, 'Failed to prepare audit query.');
    exit;
}

foreach ($params as $key => $value) {
    $paramType = $key === 'limit' ? PDO::PARAM_INT : PDO::PARAM_INT;
    $stmt->bindValue(':' . $key, $value, $paramType);
}

if ($stmt->execute() === false) {
    Http::error(500, 'Failed to load audit log.');
    exit;
}

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($rows === false) {
    $rows = [];
}

$data = [];
$nextCursor = null;

foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }

    $payload = [];
    if (isset($row['payload_json']) && $row['payload_json'] !== null) {
        try {
            $decoded = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        } catch (JsonException) {
            $payload = [];
        }
    }

    $data[] = [
        'id' => (int) $row['id'],
        'action' => (string) $row['action'],
        'subject' => [
            'type' => (string) $row['subject_type'],
            'id' => $row['subject_id'] !== null ? (int) $row['subject_id'] : null,
        ],
        'user' => [
            'id' => (int) $row['user_id'],
            'name' => (string) ($row['user_name'] ?? ''),
            'email' => (string) ($row['user_email'] ?? ''),
        ],
        'payload' => $payload,
        'created_at' => (string) $row['created_at'],
    ];

    $nextCursor = $row['id'];
}

if ($nextCursor !== null) {
    $nextCursor = (int) $nextCursor;
}

Http::json(200, [
    'data' => $data,
    'next_cursor' => $nextCursor,
]);
