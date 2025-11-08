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

    public function testTransliteratesDiacriticsInsteadOfDropping(): void
    {
        $title = "Noční zvířata - EN, CZ, EN+tit, PL, PL+tit (2016)"; // Language codes should be stripped
        $uri = "https://cdn.example.com/path/video.mkv";
        $result = \deriveOutputFilename($title, $uri);
        // Language codes removed for Jellyfin matching; diacritics transliterated.
        $this->assertSame('Nocni zvirata (2016).mkv', $result);
    }

    public function testTransliteratesPolishCharacters(): void
    {
        $title = "Zażółć gęślą jaźń – Światłość Łódź (2022)"; // No language codes, only transliteration & dash removal
        $uri = "https://cdn.example.com/video.mp4";
        $result = \deriveOutputFilename($title, $uri);
        // Polish diacritics mapped, en dash removed as unsafe, mp4 extension kept.
        $this->assertSame('Zazolc gesla jazn Swiatlosc Lodz (2022).mp4', $result);
    }

    public function testTransliteratesGermanAndFrenchCharacters(): void
    {
        $title = "Übergröße Straße & L'été brûlant – weiß Fuß Æsir (2023)"; // Mixed diacritics only
        $uri = "http://example.com/stream/file.mkv";
        $result = \deriveOutputFilename($title, $uri);
        // German: Übergröße -> Ubergroesse (ue, oe, ss expansions), Straße -> Strasse, weiß -> weiss, Fuß -> Fuss; French: L'été brûlant -> L'ete brulant; Æsir -> Aesir
        $this->assertSame("Ubergroesse Strasse L'ete brulant weiss Fuss Aesir (2023).mkv", $result);
    }

    public function testStripsLanguageCodesBeforeYear(): void
    {
        $title = "Some Movie - EN, ENG, CZ+tit, PL+sub (1999)";
        $uri = "http://e/x.mkv";
        $result = \deriveOutputFilename($title, $uri);
        $this->assertSame('Some Movie (1999).mkv', $result);
    }
}
