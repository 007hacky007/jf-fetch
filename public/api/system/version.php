<?php

declare(strict_types=1);

use App\Infra\Assets;
use App\Infra\Http;
use DateTimeImmutable;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Http::error(405, 'Method not allowed');
    return;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$assetVersion = Assets::version();

Http::json(200, [
    'data' => [
        'asset_version' => $assetVersion,
    ],
    'checked_at' => (new DateTimeImmutable())->format(DATE_ATOM),
]);
