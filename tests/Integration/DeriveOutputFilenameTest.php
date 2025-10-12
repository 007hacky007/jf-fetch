<?php

declare(strict_types=1);

namespace App\Tests\Integration;

require_once __DIR__ . '/../Support/Require.php';

use PHPUnit\Framework\TestCase;

// Prevent scheduler/worker loops from starting when including their files.
if (!defined('APP_DISABLE_DAEMONS')) {
    define('APP_DISABLE_DAEMONS', true);
}
// Load helper functions from scheduler script (deriveOutputFilename, sanitizeFilename).
$root = dirname(__DIR__, 2);
\jf_fetch_require_global($root . '/bin/scheduler.php');

final class DeriveOutputFilenameTest extends TestCase
{
    public function testDerivesExtensionFromUri(): void
    {
        $title = "Animatrix - CZ, EN (2004)";
        $uri = "https://cdn.example.com/files/abc123/stream/seg/file.mkv?token=xyz";
        $result = \deriveOutputFilename($title, $uri);
        $this->assertSame('Animatrix - CZ, EN (2004).mkv', $result);
    }

    public function testFallsBackToMkvWhenNoExtension(): void
    {
        $title = "Show Name S01E01";
        $uri = "https://cdn.example.com/download?id=123";
        $result = \deriveOutputFilename($title, $uri);
        $this->assertSame('Show Name S01E01.mkv', $result);
    }

    public function testSanitizesUnsafeCharacters(): void
    {
        $title = "Bad:/\\Name?*";
        $uri = "http://e/x.avi";
        $result = \deriveOutputFilename($title, $uri);
        $this->assertSame('Bad Name.avi', $result);
    }
}
