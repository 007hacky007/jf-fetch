<?php

declare(strict_types=1);

namespace App\Tests\Infra;

require_once __DIR__ . '/../Support/Require.php';

use App\Infra\Config;
use App\Infra\Jellyfin;
use App\Tests\TestCase;

final class JellyfinPlacementTest extends TestCase
{
    public function testPlacementUsesMetadataSeriesHints(): void
    {
        $this->bootDefaultConfig();

        $downloadsDir = (string) Config::get('paths.downloads');
        $libraryDir = (string) Config::get('paths.library');

        $sourcePath = $downloadsDir . '/kraska-test.mkv';
        file_put_contents($sourcePath, 'test-bytes');

        $metadata = [
            'source' => 'kraska',
            'menu' => [
                'trail_labels' => ['Browse', 'Městečko South Park', 'Série 02 - CZ, EN, EN+tit (1998)'],
            ],
            'hints' => [
                'series_title' => 'Městečko South Park',
                'season' => 2,
                'episode' => 2,
                'episode_title' => 'Cartmanova máma je pořád špinavá flundra',
                'language_suffix' => 'CZ, EN, EN+tit',
                'languages' => ['CZ', 'EN', 'EN+tit'],
            ],
            'item' => [
                'label' => '02x02 - Cartmanova máma je pořád špinavá flundra - CZ, EN, EN+tit',
                'meta' => [
                    'season' => 2,
                    'episode' => 2,
                    'languages' => ['CZ', 'EN', 'EN+tit'],
                ],
            ],
        ];

        $job = [
            'category' => 'TV',
            'title' => '02x02 - Cartmanova máma je pořád špinavá flundra - CZ, EN, EN+tit',
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ];

        $finalPath = Jellyfin::moveDownloadToLibrary($job, $sourcePath);

        $this->assertFileExists($finalPath);
        $this->assertFileDoesNotExist($sourcePath);
        $this->assertStringStartsWith(rtrim($libraryDir, '/') . '/Shows/', $finalPath);
        $this->assertStringContainsString('/Shows/Mestecko South Park/Season 02/', $finalPath);
        $this->assertStringContainsString('Cartmanova mama je porad spinava flundra S02E02.mkv', $finalPath);
    }
}
