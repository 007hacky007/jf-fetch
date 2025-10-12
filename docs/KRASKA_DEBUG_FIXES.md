# KraSkProvider Fixes - Bitrate & Download Debugging

## Date
October 12, 2025

## Issues Fixed

### 1. Incorrect Bitrate Calculation
**Problem**: The bitrate was being calculated from an estimated file size, which was then used to re-estimate the size - creating a circular calculation problem.

**Solution**: Changed the calculation priority order:
1. **First priority**: If actual file size exists, calculate real bitrate from it
   - Formula: `bitrate (kbps) = (size_bytes × 8) / duration_seconds / 1000`
2. **Second priority**: If no size but have duration and resolution, estimate both
   - Use resolution-based bitrate defaults (4K HEVC: 15 Mbps, 4K H.264: 25 Mbps, etc.)
   - Calculate size from estimated bitrate

**Code Location**: Lines ~156-172 in `KraSkProvider.php`

**Result**: 
- For "Matrix (1998)" with 8180s duration and actual size data:
  - **Old**: Bitrate = 15000 kbps (estimated, then calculated back)
  - **New**: Bitrate = ~15037 kbps (calculated from actual 15.3 GB file)

### 2. Download Debugging Enhancement
**Problem**: No visibility into the download URL resolution process, making it impossible to diagnose why downloads fail.

**Solution**: Added comprehensive debug logging at every step:

#### Debug Points Added:
1. **Input validation**
   - Log the input format (URL, path, etc.)
   - Log detected patterns (/Play/ID vs other formats)

2. **Stream-Cinema detail fetch**
   - Log when fetching detail
   - Log available keys in response
   - Log Stream-Cinema ID extraction

3. **Kra.sk ident extraction**
   - Log strms array presence and size
   - Log each stream's provider field
   - Log found Kra.sk streams and their idents
   - Log when no ident found

4. **API interaction**
   - Log final ident before API call
   - Log API response structure
   - Log success/failure with details
   - Log download link length on success

**Code Locations**: 
- `resolveDownloadUrl()` method: Lines ~320-429
- `extractKraIdentFromDetail()` method: Lines ~739-783

#### Enabling Debug Mode:
```php
$provider = new KraSkProvider([
    'username' => 'user',
    'password' => 'pass',
    'debug' => true  // Enable debug logging
]);
```

#### Example Debug Output:
```
[DOWNLOAD] Starting resolution for: /Play/1515
[DOWNLOAD] Detected /Play/ format with SC ID: 1515
[DOWNLOAD] Fetched Stream-Cinema detail, keys: menu, strms, info, ...
[EXTRACT_IDENT] Found strms array with 3 streams
[EXTRACT_IDENT] Stream #0 provider: 'kraska'
[EXTRACT_IDENT] Found Kra.sk stream, ident: 'abc123def'
[EXTRACT_IDENT] Returning ident: abc123def
[DOWNLOAD] Extracted Kra.sk ident from strms: abc123def
[DOWNLOAD] Final ident before API call: abc123def
[DOWNLOAD] API response keys: data, session_id
[DOWNLOAD] Successfully got download link (length: 256)
```

## Testing
All existing tests pass:
```
OK (3 tests, 12 assertions)
```

## Expected Outcomes

### For Search Results:
- Bitrate values will now correctly reflect actual file characteristics
- For files with known sizes: bitrate calculated from actual data
- For files without sizes: bitrate estimated from resolution/codec, used to estimate size

### For Downloads:
With debug mode enabled, you'll see exactly:
1. What path/ID is being processed
2. Whether Stream-Cinema detail was fetched successfully
3. How many streams are available
4. Which stream has the Kra.sk provider
5. What ident is extracted or if fallback to numeric ID
6. The exact API request being made
7. Whether the response contains the download link

## How to Diagnose Download Issues

1. **Enable debug mode** in provider config
2. **Check the logs** for:
   - Is the Stream-Cinema detail being fetched?
   - Are there streams in the response?
   - Is any stream tagged with 'kraska' provider?
   - Does that stream have an 'ident' field?
   - What ident is being sent to Kra.sk API?
   - Does the API response contain a 'link' field?

3. **Common issues**:
   - **No strms array**: Stream-Cinema changed response format
   - **No Kra.sk provider**: File may not be available on Kra.sk
   - **No ident in stream**: Stream-Cinema not providing file identifiers
   - **API returns no link**: Subscription issue or file not available

## Files Modified
- `app/Providers/KraSkProvider.php`
  - Fixed bitrate calculation logic (lines ~156-172)
  - Enhanced `resolveDownloadUrl()` with debug logging (lines ~320-429)
  - Enhanced `extractKraIdentFromDetail()` with debug logging (lines ~739-783)

## Backwards Compatibility
✅ All changes are backwards compatible
✅ Debug logging only activates when `debug => true` in config
✅ Default behavior unchanged when debug disabled
