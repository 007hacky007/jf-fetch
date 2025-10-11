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
    Auth::requireUser();
} catch (RuntimeException $exception) {
    Http::error(401, $exception->getMessage());
    exit;
}

/** @var array<int, array{label:string,path:string}> $targets */
$targets = [];

try {
    $targets[] = [
        'label' => 'downloads',
        'path' => (string) Config::get('paths.downloads'),
    ];
    $targets[] = [
        'label' => 'library',
        'path' => (string) Config::get('paths.library'),
    ];
} catch (Throwable $exception) {
    Http::error(500, 'Storage paths are not configured.', ['detail' => $exception->getMessage()]);
    exit;
}

$mounts = [];

foreach ($targets as $target) {
    $path = $target['path'];
    $resolved = realpath($path) ?: null;
    $probe = $resolved ?? $path;

    $total = @disk_total_space($probe);
    $free = @disk_free_space($probe);

    if ($total === false || $free === false) {
        $mounts[] = [
            'label' => $target['label'],
            'path' => $path,
            'resolved_path' => $resolved,
            'status' => 'unavailable',
            'error' => is_dir($probe) ? 'Unable to read disk statistics.' : 'Path does not exist.',
        ];

        continue;
    }

    $used = max(0, $total - $free);
    $usedPercent = $total > 0 ? round(($used / $total) * 100, 2) : null;

    $mounts[] = [
        'label' => $target['label'],
        'path' => $path,
        'resolved_path' => $resolved,
        'status' => 'ok',
        'total_bytes' => $total,
        'free_bytes' => $free,
        'used_bytes' => $used,
        'used_percent' => $usedPercent,
        'total_gb' => round($total / (1024 ** 3), 2),
        'free_gb' => round($free / (1024 ** 3), 2),
        'used_gb' => round($used / (1024 ** 3), 2),
    ];
}

Http::json(200, [
    'mounts' => $mounts,
    'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
]);
