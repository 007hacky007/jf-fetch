<?php

declare(strict_types=1);

use App\Infra\Auth;
use App\Infra\Config;
use App\Infra\Http;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

Config::boot(dirname(__DIR__, 3) . '/config');
Auth::boot();

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Http::error(405, 'Method not allowed');
    exit;
}

try {
    Auth::requireRole('admin');
} catch (RuntimeException $exception) {
    Http::error(403, $exception->getMessage());
    exit;
}

$allowed = [
    'scheduler.log',
    'scheduler-error.log',
    'worker.log',
    'worker-error.log',
    'aria2.log',
    'aria2-error.log',
];

$file = isset($_GET['file']) ? (string) $_GET['file'] : '';
$linesParam = isset($_GET['lines']) ? (int) $_GET['lines'] : 100;
$lines = max(1, min(500, $linesParam));

if ($file === '') {
    // List available logs and their sizes.
    $logDir = dirname(__DIR__, 3) . '/storage/logs';
    $data = [];
    foreach ($allowed as $name) {
        $path = $logDir . '/' . $name;
        $size = is_file($path) ? filesize($path) : null;
        $data[] = [
            'name' => $name,
            'size_bytes' => $size !== false ? $size : null,
        ];
    }
    Http::json(200, [
        'data' => $data,
    ]);
    exit;
}

if (!in_array($file, $allowed, true)) {
    Http::error(400, 'Unsupported log file.');
    exit;
}

$logPath = dirname(__DIR__, 3) . '/storage/logs/' . $file;
if (!is_file($logPath)) {
    Http::error(404, 'Log file not found.');
    exit;
}

$content = tailFile($logPath, $lines);

Http::json(200, [
    'file' => $file,
    'lines' => $lines,
    'data' => $content,
]);
exit;

/**
 * Returns the last N lines from the provided file with basic truncation safeguards.
 *
 * @return array<int, string>
 */
function tailFile(string $path, int $lines): array
{
    $lines = max(1, $lines);
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return ['<unable to open file>'];
    }

    $bufferSize = 4096;
    $pos = -1;
    $chunk = '';
    $output = [];
    $newlineCount = 0;
    $seekFailures = 0;

    while ($newlineCount <= $lines && $seekFailures < 5) {
        if (fseek($handle, $pos, SEEK_END) !== 0) {
            // Start of file reached.
            $seekFailures++;
            break;
        }
        $char = fgetc($handle);
        if ($char === false) {
            break;
        }
        if ($char === "\n") {
            $newlineCount++;
            if ($newlineCount > 1) {
                $output[] = strrev($chunk);
                $chunk = '';
            }
        } else {
            $chunk .= $char;
        }
        $pos--;
        if ($pos < - (1024 * 1024)) { // safety hard limit 1MB backwards
            break;
        }
    }
    if ($chunk !== '') {
        $output[] = strrev($chunk);
    }
    fclose($handle);

    $filtered = array_values(array_filter(array_map('trim', array_reverse($output)), fn($line) => $line !== ''));
    return array_slice($filtered, -$lines);
}
