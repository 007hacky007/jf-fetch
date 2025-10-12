# KraSkProvider Download Fix

## Issues Found and Fixed

### 1. **Scheduler Missing KraSkProvider** (CRITICAL)
**Problem**: The scheduler's `buildProvider()` function only supported Webshare provider. When trying to download from KraSkProvider, it would throw "Unsupported provider: kraska" error.

**Location**: `bin/scheduler.php` line ~230

**Fix**: Added KraSkProvider case to the match statement:
```php
return match ($key) {
    'webshare' => new WebshareProvider($config),
    'kraska' => new App\Providers\KraSkProvider($config),
    default => throw new RuntimeException('Unsupported provider: ' . $key),
};
```

### 2. **Debug Log Path Incorrect**
**Problem**: The debug log path calculation used `dirname(__DIR__, 3)` which went one level too high.

**Location**: `app/Providers/KraSkProvider.php` line ~955

**Fix**: Changed to `dirname(__DIR__, 2)` to correctly resolve to project root:
```php
// app/Providers -> app -> project root (2 levels up)
$root = dirname(__DIR__, 2);
$file = $root . '/storage/logs/kraska_debug.log';
```

### 3. **Bitrate Display Issue**
**Problem**: Frontend expected `bitrate_kbps` but KraSkProvider was returning `bitrate`, causing the frontend to divide by 1000 thinking it was in bps, resulting in "15 kbps" instead of "15 Mbps".

**Location**: `app/Providers/KraSkProvider.php` line ~551

**Fix**: Changed field name from `'bitrate'` to `'bitrate_kbps'`:
```php
return [
    // ...
    'bitrate_kbps' => $bitrate,  // Changed from 'bitrate'
    // ...
];
```

### 4. **Debug Checkbox Added to UI**
**Location**: `public/ui/app.js` line ~1310

**Added**: Debug mode checkbox in provider settings form:
- Displays below "Provider enabled" checkbox
- Reads/saves debug flag to provider config
- Shows helpful text explaining log location

## How to Use

### Enable Debug Logging
1. Go to Settings → Providers
2. Click "Edit" on your KraSkProvider
3. Check the "Debug mode" checkbox
4. Click "Save changes"

### View Debug Logs
Debug logs are written to: `storage/logs/kraska_debug.log`

To view logs in real-time:
```bash
tail -f storage/logs/kraska_debug.log
```

### Restart Services (IMPORTANT)
After these code changes, you must restart the scheduler and worker:

**Docker:**
```bash
docker-compose restart
```

**Or individual services:**
```bash
supervisorctl restart scheduler
supervisorctl restart worker
```

## Testing Downloads

1. Search for a video from KraSkProvider
2. Queue it for download
3. Watch the logs:
   ```bash
   tail -f storage/logs/kraska_debug.log
   tail -f storage/logs/scheduler.log
   tail -f storage/logs/worker.log
   ```

You should now see detailed debug output showing:
- Download resolution starting
- Stream-Cinema detail fetch
- Ident extraction from strms
- API calls to Kra.sk
- Download link retrieval

## Log Output Example

When debug mode is enabled, you'll see logs like:
```
[2025-10-12T17:24:54+00:00] [DOWNLOAD] Starting resolution for: /Play/1515
[2025-10-12T17:24:54+00:00] [DOWNLOAD] Detected /Play/ format with SC ID: 1515
[2025-10-12T17:24:54+00:00] [DOWNLOAD] Fetched Stream-Cinema detail, keys: id, type, url, info, strms...
[2025-10-12T17:24:54+00:00] [EXTRACT_IDENT] Found strms array with 3 streams
[2025-10-12T17:24:54+00:00] [EXTRACT_IDENT] Stream #0 provider: 'kraska'
[2025-10-12T17:24:54+00:00] [EXTRACT_IDENT] Returning ident: abc123xyz
[2025-10-12T17:24:54+00:00] [DOWNLOAD] Extracted Kra.sk ident from strms: abc123xyz
[2025-10-12T17:24:54+00:00] [DOWNLOAD] Final ident before API call: abc123xyz
[2025-10-12T17:24:54+00:00] [DOWNLOAD] API response keys: data
[2025-10-12T17:24:54+00:00] [DOWNLOAD] Successfully got download link (length: 256)
```

## All Tests Passing
✅ All KraSkProvider unit tests pass (3 tests, 12 assertions)
