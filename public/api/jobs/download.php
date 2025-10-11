<?php

declare(strict_types=1);

use App\Domain\Jobs;
use App\Infra\Auth;
use App\Infra\Http;
use App\Infra\Config;

// We will output binary data; don't set JSON content-type globally.

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Content-Type: application/json');
    Http::error(405, 'Method not allowed');
    exit;
}

try {
    Auth::boot();
    Auth::requireUser();
} catch (RuntimeException $exception) {
    header('Content-Type: application/json');
    Http::error(401, $exception->getMessage());
    exit;
}

$user = Auth::user();
if ($user === null) {
    header('Content-Type: application/json');
    Http::error(401, 'Authentication required.');
    exit;
}

$jobId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($jobId <= 0) {
    header('Content-Type: application/json');
    Http::error(400, 'Job ID is required.');
    exit;
}

$job = Jobs::fetchById($jobId);
if ($job === null) {
    header('Content-Type: application/json');
    Http::error(404, 'Job not found.');
    exit;
}

$isAdmin = Auth::isAdmin();
if (!$isAdmin && (int) $job['user_id'] !== (int) $user['id']) {
    header('Content-Type: application/json');
    Http::error(403, 'Insufficient permissions to download this file.');
    exit;
}

if (($job['status'] ?? '') !== 'completed') {
    header('Content-Type: application/json');
    Http::error(422, 'Only completed jobs can be downloaded.');
    exit;
}

$finalPath = isset($job['final_path']) ? (string) $job['final_path'] : '';
if ($finalPath === '') {
    header('Content-Type: application/json');
    Http::error(422, 'No output file is associated with this job.');
    exit;
}

// Security: ensure the path is within configured downloads or library directories.
$downloadsRoot = rtrim(Config::get('paths.downloads', ''), '/');
$libraryRoot   = rtrim(Config::get('paths.library', ''), '/');
$realPath = realpath($finalPath);
if ($realPath === false) {
    header('Content-Type: application/json');
    Http::error(404, 'File not found on disk.');
    exit;
}

$allowed = false;
foreach ([$downloadsRoot, $libraryRoot] as $root) {
    if ($root === '') continue;
    $realRoot = realpath($root);
    if ($realRoot !== false && str_starts_with($realPath, $realRoot)) {
        $allowed = true;
        break;
    }
}

if (!$allowed) {
    header('Content-Type: application/json');
    Http::error(403, 'File location not permitted for download.');
    exit;
}

$filename = basename($realPath);
$filesize = filesize($realPath);
if ($filesize === false) {
    header('Content-Type: application/json');
    Http::error(500, 'Unable to read file metadata.');
    exit;
}

// Attempt to guess content type; fallback to octet-stream.
$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
$mime = $finfo ? finfo_file($finfo, $realPath) : null;
if ($finfo && is_resource($finfo)) {
    finfo_close($finfo);
}
if (!is_string($mime) || $mime === '') {
    $mime = 'application/octet-stream';
}

// Disable output buffering to stream large files.
if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . $filesize);
header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-cache');

$chunkSize = 8192;
$fh = fopen($realPath, 'rb');
if ($fh === false) {
    header('Content-Type: application/json');
    Http::error(500, 'Failed to open file for reading.');
    exit;
}

while (!feof($fh)) {
    $buffer = fread($fh, $chunkSize);
    if ($buffer === false) {
        break;
    }
    echo $buffer;
    flush();
}

fclose($fh);
exit;
