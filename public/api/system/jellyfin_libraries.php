<?php

declare(strict_types=1);

use App\Infra\Auth;
use App\Infra\Config;
use App\Infra\Http;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

$url = rtrim((string) Config::get('jellyfin.url'), '/');
$apiKey = (string) Config::get('jellyfin.api_key');

if ($url === '' || $apiKey === '') {
    Http::json(200, ['data' => [], 'message' => 'Jellyfin not configured']);
    exit;
}

$endpoint = $url . '/Library/VirtualFolders';

$handle = curl_init($endpoint);
if ($handle === false) {
    Http::error(500, 'Failed to initialize request');
    exit;
}

curl_setopt_array($handle, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [ 'X-Emby-Token: ' . $apiKey ],
]);

$response = curl_exec($handle);
if ($response === false) {
    $err = curl_error($handle);
    curl_close($handle);
    Http::error(502, 'Request to Jellyfin failed', ['error' => $err]);
    exit;
}
$code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
curl_close($handle);

if ($code >= 400) {
    Http::error(502, 'Jellyfin responded with an error', ['status' => $code]);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data)) {
    Http::error(500, 'Unexpected response from Jellyfin');
    exit;
}

$libraries = [];
foreach ($data as $item) {
    if (!is_array($item) || !isset($item['ItemId'], $item['Name'])) {
        continue;
    }
    $libraries[] = [
        'id' => (string) $item['ItemId'],
        'name' => (string) $item['Name'],
        'collection_type' => isset($item['CollectionType']) ? (string) $item['CollectionType'] : null,
    ];
}

Http::json(200, ['data' => $libraries]);
