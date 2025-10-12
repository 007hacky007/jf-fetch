<?php

declare(strict_types=1);

use App\Domain\Jobs;
use App\Infra\Auth;
use App\Infra\Http;



if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Http::error(405, 'Method not allowed');
    exit;
}

$overrides = $GLOBALS['__jobsStreamTest'] ?? [];

$authClass = $overrides['authClass'] ?? Auth::class;
$jobsClass = $overrides['jobsClass'] ?? Jobs::class;
$rowLimit = (int) ($overrides['rowLimit'] ?? 100);
$timeProvider = $overrides['timeProvider'] ?? static fn (): float => microtime(true);
$connectionChecker = $overrides['connectionAborted'] ?? static fn (): bool => (bool) connection_aborted();
$flush = $overrides['flush'] ?? static function (): void {
    if (function_exists('ob_get_level') && ob_get_level() > 0) {
        @ob_flush();
    }
    flush();
};
$sleep = $overrides['sleep'] ?? static function (int $microseconds): void {
    usleep($microseconds);
};
$write = $overrides['write'] ?? static function (string $chunk): void {
    echo $chunk;
};
$updatedSince = $overrides['updatedSince'] ?? static function (
    string $since,
    bool $isAdmin,
    int $userId,
    int $limit,
    ?int $afterId
) use ($jobsClass) {
    return $jobsClass::updatedSince($since, $isAdmin, $userId, $limit, $afterId);
};
$formatJob = $overrides['formatJob'] ?? static function (array $row, bool $isAdmin) use ($jobsClass) {
    return $jobsClass::format($row, $isAdmin);
};
$maxLoops = $overrides['maxLoops'] ?? null;
$suppressExit = !empty($overrides['suppressExit']);

try {
    $authClass::boot();
    $authClass::requireUser();
} catch (RuntimeException $exception) {
    Http::error(401, $exception->getMessage());
    exit;
}
$user = $authClass::user();
if ($user === null) {
    Http::error(401, 'Authentication required.');
    exit;
}

$isAdmin = (bool) $authClass::isAdmin();

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (function_exists('ignore_user_abort')) {
    ignore_user_abort(true);
}

if (function_exists('set_time_limit')) {
    set_time_limit(0);
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Tell nginx not to buffer

// Disable implicit flush if enabled
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');

while (ob_get_level() > 0) {
    @ob_end_flush();
}

// Send padding to force nginx to start sending data immediately
// Some servers buffer until a certain amount of data is sent
$disablePadding = !empty($overrides['disablePadding']);
if (!$disablePadding) {
    $write(str_repeat(":\n", 10)); // Send 10 comment lines as padding
    $flush();
}

$lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? ($_GET['since'] ?? '');
$since = '1970-01-01T00:00:00.000000+00:00';
$afterId = null;

if (is_string($lastEventId) && $lastEventId !== '') {
    $parts = explode('|', $lastEventId, 2);
    $timestampPart = $parts[0] ?? '';
    $idPart = $parts[1] ?? null;

    try {
        $since = (new DateTimeImmutable($timestampPart))->format('Y-m-d\TH:i:s.uP');
    } catch (Throwable $exception) {
        $since = '1970-01-01T00:00:00.000000+00:00';
    }

    if (is_string($idPart) && ctype_digit($idPart)) {
        $afterId = (int) $idPart;
    }
}

$heartbeatInterval = 15.0;
$pollIntervalMicros = 250_000;
$lastHeartbeatSent = $timeProvider();
$loopCount = 0;

$write(": connected\n");
$write("retry: 3000\n\n");
$flush();

while (!$connectionChecker()) {
    if ($maxLoops !== null && $loopCount >= $maxLoops) {
        break;
    }
    $loopCount++;

    $hadUpdates = false;

    try {
        $rows = $updatedSince($since, $isAdmin, (int) $user['id'], $rowLimit, $afterId);
        // Debug logging - always log for troubleshooting
        $debugMsg = sprintf('[SSE] Loop %d: Query since=%s afterId=%s returned %d rows', $loopCount, $since, $afterId ?? 'null', count($rows));
        error_log($debugMsg);
    } catch (Throwable $exception) {
        error_log(sprintf('[SSE ERROR] Query failed: %s', $exception->getMessage()));
        $write("event: error\n");
        $write('data: ' . json_encode([
            'message' => 'Failed to fetch job updates.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n");
        $flush();
        $sleep($pollIntervalMicros);
        continue;
    }

    if ($rows !== []) {
        foreach ($rows as $row) {
            $formatted = $formatJob($row, $isAdmin);
            $eventTimestamp = (string) $row['updated_at'];
            $eventJobId = (int) $row['id'];
            $eventId = $eventTimestamp . '|' . $eventJobId;
            
            // Debug logging - always log for troubleshooting (guard missing keys)
            $rawStatus = $row['status'] ?? 'unknown';
            $rawProgress = isset($row['progress']) && is_numeric($row['progress']) ? (int) $row['progress'] : 0;
            error_log(sprintf('[SSE] Sending event for job %d, status=%s, progress=%d, updated_at=%s', $eventJobId, $rawStatus, $rawProgress, $eventTimestamp));
            
            $since = $eventTimestamp;
            $afterId = $eventJobId;

            $eventName = match ($row['status']) {
                'completed' => 'job.completed',
                'failed' => 'job.failed',
                'canceled' => 'job.removed',
                'deleted' => 'job.deleted',
                default => 'job.updated',
            };

            $write('id: ' . $eventId . "\n");
            $write('event: ' . $eventName . "\n");
            $write('data: ' . json_encode($formatted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n");
            $flush();
            
            // Add extra flush with padding to force send
            // Some buffering layers need more data to trigger actual sending
            if (function_exists('ob_get_level') && ob_get_level() > 0) {
                @ob_flush();
            }
            @flush();
            
            $hadUpdates = true;
        }
    }

    $now = $timeProvider();
    if (($now - $lastHeartbeatSent) >= $heartbeatInterval) {
        $write(': heartbeat ' . (string) $now . "\n\n");
        $flush();
        $lastHeartbeatSent = $now;
    }

    if ($hadUpdates) {
        continue;
    }

    $sleep($pollIntervalMicros);
}

error_log(sprintf('[SSE] Stream ended after %d loops. Connection aborted: %s', $loopCount, connection_aborted() ? 'YES' : 'NO'));

if ($suppressExit) {
    return;
}

exit;
