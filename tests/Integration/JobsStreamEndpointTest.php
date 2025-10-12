<?php

declare(strict_types=1);

namespace App\Tests\Integration;

require_once __DIR__ . '/../Support/Require.php';

use App\Tests\TestCase;

final class JobsStreamAuthStub
{
    private static ?array $user = ['id' => 99];

    public static bool $booted = false;

    public static function boot(): void
    {
        self::$booted = true;
    }

    public static function requireUser(): void
    {
        // no-op for tests
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function isAdmin(): bool
    {
        return true;
    }

    public static function setUser(?array $user): void
    {
        self::$user = $user;
    }

    public static function reset(): void
    {
        self::$booted = false;
        self::$user = ['id' => 99];
    }
}

final class JobsStreamEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        JobsStreamAuthStub::reset();
    }

    protected function tearDown(): void
    {
        JobsStreamAuthStub::reset();
        parent::tearDown();
    }

    public function testStreamsJobUpdatesAndHeartbeatWithOverrides(): void
    {
        $capturedUpdatedCalls = [];
        $capturedFormatCalls = [];
        $rows = [
            [
                'id' => 42,
                'status' => 'completed',
                'updated_at' => '2025-01-01T12:00:00.000000+00:00',
            ],
        ];
        $sequence = [$rows, []];
        $timeSequence = [1000.0, 1005.0, 1016.0, 1016.0];

        $result = $this->executeStream([
            'authClass' => JobsStreamAuthStub::class,
            'rowLimit' => 25,
            'maxLoops' => 2,
            'disablePadding' => true,
            'timeProvider' => static function () use (&$timeSequence): float {
                $value = array_shift($timeSequence);
                if ($value === null) {
                    return 0.0;
                }

                return $value;
            },
            'updatedSince' => static function (
                string $since,
                bool $isAdmin,
                int $userId,
                int $limit,
                ?int $afterId
            ) use (&$sequence, &$capturedUpdatedCalls) {
                $capturedUpdatedCalls[] = [$since, $isAdmin, $userId, $limit, $afterId];
                $next = array_shift($sequence);
                return $next ?? [];
            },
            'formatJob' => static function (array $row, bool $isAdmin) use (&$capturedFormatCalls): array {
                $capturedFormatCalls[] = [$row, $isAdmin];
                return [
                    'id' => (int) $row['id'],
                    'status' => (string) $row['status'],
                    'admin' => $isAdmin,
                ];
            },
        ]);

        $expectedOutput = ''
            . ": connected\n"
            . "retry: 3000\n\n"
            . "id: 2025-01-01T12:00:00.000000+00:00|42\n"
            . "event: job.completed\n"
            . 'data: {"id":42,"status":"completed","admin":true}' . "\n\n"
            . ": heartbeat 1016\n\n";

        $this->assertSame($expectedOutput, $result['output']);
    $this->assertSame(3, $result['flushCount']);
    $this->assertSame([250000], $result['sleepCalls']);
        $this->assertSame([
            ['1970-01-01T00:00:00.000000+00:00', true, 99, 25, null],
            ['2025-01-01T12:00:00.000000+00:00', true, 99, 25, 42],
        ], $capturedUpdatedCalls);
        $this->assertCount(1, $capturedFormatCalls);
        $this->assertSame($rows[0], $capturedFormatCalls[0][0]);
        $this->assertTrue($capturedFormatCalls[0][1]);
    }

    public function testStreamsErrorEventWhenUpdatedSinceThrows(): void
    {
        $result = $this->executeStream([
            'authClass' => JobsStreamAuthStub::class,
            'maxLoops' => 1,
            'disablePadding' => true,
            'timeProvider' => static function (): float {
                return 2000.0;
            },
            'updatedSince' => static function (): array {
                throw new \RuntimeException('db offline');
            },
        ]);

        $expectedOutput = ''
            . ": connected\n"
            . "retry: 3000\n\n"
            . "event: error\n"
            . 'data: {"message":"Failed to fetch job updates."}' . "\n\n";

        $this->assertSame($expectedOutput, $result['output']);
    $this->assertSame(2, $result['flushCount']);
        $this->assertSame([250000], $result['sleepCalls']);
    }

    /**
     * @param array<string, mixed> $overrideConfig
     * @param array<string, mixed> $serverOverrides
     * @param array<string, mixed> $getOverrides
     *
    * @return array{
    *     output:string,
    *     flushCount:int,
    *     flushLog:array<int>,
    *     sleepCalls:array<int>,
    *     connectionChecks:int
    * }
     */
    private function executeStream(
        array $overrideConfig,
        array $serverOverrides = [],
        array $getOverrides = []
    ): array {
        $script = dirname(__DIR__, 2) . '/public/api/jobs/stream.php';

        $capturedOutput = '';
    $flushCount = 0;
    $flushLog = [];
        $sleepCalls = [];
        $connectionChecks = 0;

        $overrides = $overrideConfig;
        $overrides['suppressExit'] = true;

        if (!array_key_exists('authClass', $overrides)) {
            $overrides['authClass'] = JobsStreamAuthStub::class;
        }

        if (!array_key_exists('write', $overrides)) {
            $overrides['write'] = static function (string $chunk) use (&$capturedOutput): void {
                $capturedOutput .= $chunk;
            };
        } else {
            $originalWrite = $overrides['write'];
            $overrides['write'] = static function (string $chunk) use (&$capturedOutput, $originalWrite): void {
                $capturedOutput .= $chunk;
                $originalWrite($chunk);
            };
        }

        if (!array_key_exists('flush', $overrides)) {
            $overrides['flush'] = static function () use (&$flushCount, &$flushLog): void {
                $flushCount++;
                $flushLog[] = $flushCount;
            };
        } else {
            $originalFlush = $overrides['flush'];
            $overrides['flush'] = static function () use (&$flushCount, &$flushLog, $originalFlush): void {
                $flushCount++;
                $flushLog[] = $flushCount;
                $originalFlush();
            };
        }

        if (!array_key_exists('sleep', $overrides)) {
            $overrides['sleep'] = static function (int $microseconds) use (&$sleepCalls): void {
                $sleepCalls[] = $microseconds;
            };
        } else {
            $originalSleep = $overrides['sleep'];
            $overrides['sleep'] = static function (int $microseconds) use (&$sleepCalls, $originalSleep): void {
                $sleepCalls[] = $microseconds;
                $originalSleep($microseconds);
            };
        }

        if (!array_key_exists('connectionAborted', $overrides)) {
            $overrides['connectionAborted'] = static function () use (&$connectionChecks): bool {
                $connectionChecks++;
                return false;
            };
        } else {
            $originalChecker = $overrides['connectionAborted'];
            $overrides['connectionAborted'] = static function () use (&$connectionChecks, $originalChecker): bool {
                $connectionChecks++;
                return $originalChecker();
            };
        }

        $originalOverrides = $GLOBALS['__jobsStreamTest'] ?? null;
        $originalServer = $_SERVER;
        $originalGet = $_GET;
        $originalBufferLevel = ob_get_level();

        $_SERVER = array_merge($originalServer, ['REQUEST_METHOD' => 'GET'], $serverOverrides);
        $_GET = $getOverrides;

        $GLOBALS['__jobsStreamTest'] = $overrides;

        try {
            require $script;
        } finally {
            if ($originalOverrides === null) {
                unset($GLOBALS['__jobsStreamTest']);
            } else {
                $GLOBALS['__jobsStreamTest'] = $originalOverrides;
            }

            $_SERVER = $originalServer;
            $_GET = $originalGet;

            while (ob_get_level() < $originalBufferLevel) {
                ob_start();
            }
        }

        return [
            'output' => $capturedOutput,
            'flushCount' => $flushCount,
            'flushLog' => $flushLog,
            'sleepCalls' => $sleepCalls,
            'connectionChecks' => $connectionChecks,
        ];
    }
}
