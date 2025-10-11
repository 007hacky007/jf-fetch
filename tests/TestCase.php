<?php

declare(strict_types=1);

namespace App\Tests;

use App\Infra\Auth;
use App\Infra\Config;
use App\Infra\Db;
use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected string $configDir;

    protected ?PDO $pdo = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configDir = sys_get_temp_dir() . '/jf-fetch-tests-' . bin2hex(random_bytes(5));
        if (!is_dir($this->configDir)) {
            mkdir($this->configDir, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo instanceof PDO) {
            $this->pdo = null;
        }

        Db::disconnect();
        Config::reset();
        Auth::reset();

        $this->removeDirectory($this->configDir);
        parent::tearDown();
    }

    /**
     * Boots configuration using the provided file/section map.
     *
     * @param array<string, array<string, array<string, scalar>>> $files
     */
    protected function bootConfig(array $files): void
    {
        foreach ($files as $filename => $sections) {
            $ini = $this->buildIni($sections);
            file_put_contents($this->configDir . '/' . $filename . '.ini', $ini);
        }

        Config::boot($this->configDir);
    }

    /**
     * Boots the default configuration commonly used by tests.
     *
     * @param array<string, array<string, scalar>> $overrides
     */
    protected function bootDefaultConfig(array $overrides = []): void
    {
        $downloads = $overrides['paths']['downloads'] ?? ($this->configDir . '/downloads');
        $library = $overrides['paths']['library'] ?? ($this->configDir . '/library');

        if (!is_dir($downloads)) {
            mkdir($downloads, 0775, true);
        }

        if (!is_dir($library)) {
            mkdir($library, 0775, true);
        }

        $defaults = [
            'app' => [
                'session_name' => 'JF_FETCH_TEST',
                'max_active_downloads' => 2,
                'min_free_space_gb' => 0,
            ],
            'db' => [
                'dsn' => 'sqlite::memory:',
                'user' => '',
                'pass' => '',
            ],
            'paths' => [
                'downloads' => $downloads,
                'library' => $library,
            ],
            'aria2' => [
                'rpc_url' => 'http://localhost:6800/jsonrpc',
                'secret' => '',
            ],
            'jellyfin' => [
                'url' => '',
                'api_key' => '',
            ],
            'security' => [
                'provider_secret' => 'test-secret',
            ],
        ];

        $config = array_replace_recursive($defaults, $overrides);
        $this->bootConfig(['app' => $config]);
    }

    /**
     * Prepares and registers an in-memory SQLite database connection.
     */
    protected function useInMemoryDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        Db::setConnection($pdo);
        $this->pdo = $pdo;

        return $pdo;
    }

    /**
     * Builds an INI string from the supplied configuration sections.
     *
     * @param array<string, array<string, scalar>> $sections
     */
    private function buildIni(array $sections): string
    {
        $buffer = '';
        foreach ($sections as $section => $values) {
            $buffer .= '[' . $section . "]\n";
            foreach ($values as $key => $value) {
                $buffer .= $key . ' = ' . $this->formatIniValue($value) . "\n";
            }
            $buffer .= "\n";
        }

        return $buffer;
    }

    private function formatIniValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '"' . addcslashes((string) $value, "\"") . '"';
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }
}
