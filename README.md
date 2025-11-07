# JF Fetch

Early scaffold for the JF Fetch application. Follow `docs/COPILOT_SPEC.md` for full implementation details.

## Local Setup (without Docker)

Composer is used for autoloading. Install dependencies:

```sh
composer install
```

Run database migrations to create the schema before starting the app:

```sh
php bin/migrate.php
```

Background processes handle scheduling and download progress. During development you can run them manually in separate terminals:

```sh
php bin/scheduler.php
php bin/worker.php
```

Point your PHP runtime at `public/` (e.g., with `php -S 127.0.0.1:8000 -t public`) or serve through nginx/Apache configured to hit `public/index.php`.

## Containerized Deployment

The repository ships with a single-container image that bundles nginx, php-fpm, aria2c, the scheduler, and the worker under `supervisord`. The image targets macOS (Intel/Apple Silicon) and Linux.

### Prerequisites

- Docker 24+ (or Docker Desktop on macOS)
- `docker compose` plugin (built-in on recent Docker versions)

Ensure the following directories exist on the host so they can be mounted:

- `downloads/` — aria2 staging downloads
- `library/` — finalized media imported into Jellyfin
- `config/` — INI configuration files (mounted read-only)
- `storage/` — database and logs

### Environment configuration

Set the aria2 RPC secret before running the stack. The compose file reads `ARIA2_SECRET` from your environment. You can export it inline or create a `.env` file alongside `docker-compose.yml`:

```sh
echo "ARIA2_SECRET=supersecret" >> .env
```

Optionally override the runtime user/group when bind-mounting to host filesystems:

```sh
echo "PUID=$(id -u)" >> .env
echo "PGID=$(id -g)" >> .env
```

### Build the image

To build a multi-arch image suitable for both amd64 and arm64:

```sh
docker buildx build --platform linux/amd64,linux/arm64 -t jf-fetch:latest .
```

For a quicker local build targeting your current platform only:

```sh
docker build -t jf-fetch:latest .
```

### Run with Docker Compose

```sh
docker compose up -d
```

The service binds port `8080` on the host. Visit `http://localhost:8080`. Persistent data lives in the mounted `downloads/`, `library/`, and `storage/` directories. Configuration changes should be made in `config/*.ini` on the host; the container reads them on startup.

### Default credentials

After the first migration run, a default administrator account is created automatically:

- Email: `admin@example.com`
- Password: `changeme`

Use it to sign in, then create permanent accounts and rotate the password right away.

On Apple Silicon, if a dependency lacks arm64 builds you can force an amd64 image by uncommenting `platform: linux/amd64` in `docker-compose.yml`.

## Project Structure

- `public/`: Web root and API entrypoints.
- `app/`: Application source code (PSR-4 autoloaded under the `App\` namespace).
- `config/`: INI configuration files consumed at runtime.
- `bin/`: CLI workers and scheduler scripts.
- `storage/`: Persistent data such as database and logs.
- `downloads/`, `library/`: Media staging and final library directories.

Consult `docs/COPILOT_SPEC.md` for the roadmap.

## API Endpoints (Selected)

Key endpoints exposed under `public/api/` (not exhaustive):

- `GET /api/jobs/list` – Paged job listing with metadata and pagination meta.
- `GET /api/jobs/stream` – Server‑sent events stream (incremental updates via since/after_id).
- `GET /api/jobs/stats` – Aggregated statistics for the job queue. Example response:

```json
{
  "data": {
    "total_jobs": 42,
    "completed_jobs": 20,
    "active_jobs": 2,
    "queued_jobs": 5,
    "paused_jobs": 1,
    "canceled_jobs": 3,
    "failed_jobs": 2,
    "deleted_jobs": 9,
    "distinct_users": 4,
    "total_bytes_downloaded": 9876543210,
    "total_download_duration_seconds": 123456,
    "avg_download_duration_seconds": 6172,
    "success_rate_pct": 48
  }
}
```

`total_bytes_downloaded` and durations are derived by scanning completed job files unless persisted; consider caching for very large libraries.

## Configuration

`config/app.ini` contains all runtime settings. The `[aria2]` section defines the JSON-RPC endpoint and secret used by `App\Download\Aria2Client`, while `[webshare]` stores the `wst` token consumed by `App\Providers\WebshareProvider`. Other sections configure Jellyfin, storage paths, and database connectivity. Avoid hard-coding configuration elsewhere—update the INI files instead.

### Jellyfin integration

Provide your Jellyfin **Server URL** and **API key** in the Settings tab to enable automatic library refreshes after a download completes or a previously completed file is deleted. For faster, scoped scans you can optionally set a **Library ID** (Virtual Folder ID). Use the "Fetch libraries" button to query `/Library/VirtualFolders` and select from the available libraries; the selected ID is stored in `jellyfin.library_id`.

Refresh behavior:

1. If `jellyfin.url`, `jellyfin.api_key`, and `jellyfin.library_id` are set, the app sends a targeted POST to:
  `/Items/<LIBRARY_ID>/Refresh?Recursive=true&ImageRefreshMode=Default&MetadataRefreshMode=Default&ReplaceAllImages=false&RegenerateTrickplay=false&ReplaceAllMetadata=false` with `X-Emby-Token: <API_KEY>`.
2. If only URL + API key are set, it falls back to a global `/Library/Refresh` request.
3. If configuration is incomplete, the refresh is skipped (logged for diagnostics).

Selecting a library ID avoids a full-library scan (especially helpful on large multi‑TB libraries) and keeps Jellyfin responsive.

### Adding the Webshare provider

1. Sign in with an administrator account and open the **Providers** tab.
2. Click **Add provider** and keep the default **Webshare** type selected.
3. Enter your Webshare **username (or email)** and **password**. The password is encrypted at rest and never shown again.
4. Choose a display name if you prefer something other than “Webshare”, leave the provider enabled, and click **Create provider**.

JF Fetch signs in to Webshare, requests a fresh API token, and stores it automatically—no manual JSON needed. Tokens are refreshed again whenever credentials are updated.

### Adding the Kra.sk provider

The Kra.sk provider integrates the private API used by the Stream-Cinema Kodi addon (reverse‑engineered endpoints). It supports searching shared files and resolving direct download links.

1. Open the **Providers** tab and click **Add provider**.
2. Select **Kra.sk** as the provider type.
3. Supply your Kra.sk **username** and **password** (required; both stored encrypted at rest).
4. (Optional) Provide a static **X-Uuid** value. If set, this value is sent in the `X-Uuid` header for all Stream‑Cinema token + search requests instead of generating a random UUID. Any 5–50 character identifier containing letters, digits, and dashes is accepted (it does not have to be a canonical hex UUID). Some accounts / upstream heuristics may bind authorization tokens to a stable client UUID; specifying one ensures continuity across container restarts.
5. Save. The application performs a lightweight credential validation attempt.

Usage notes:

Search path: This app mirrors the Kodi addon multi‑stage flow:

1. Acquire/refresh a Stream‑Cinema auth token via `POST /auth/token?krt=<kra_session>` (headers include `X-Uuid` — static if you configured one).
2. Perform an authenticated GET: `https://stream-cinema.online/kodi/Search/search?search=<q>&id=search`.
3. Parse `menu` entries; each may embed basic metadata (`quality`, `lang`, `bitrate`).
4. For entries that are navigational paths (e.g. `/Movie/12345`) fetch detail JSON to inspect `strms`.
5. Select the first stream with provider `kraska` and extract its `ident`.
6. Resolve ident via Kra.sk: `POST /api/file/download` to get the ephemeral direct download URL (`data.link`).


- A session is established lazily via `/api/user/login` (response `session_id`). The session is cached in memory for up to 30 minutes; on expiration or auth failure it is transparently re‑negotiated.
- Subscription status is verified via `/api/user/info`. If the account lacks an active subscription (no `subscribed_until` field) an error is raised and the action is aborted.

Security considerations:

- Credentials never leave the backend; only a session identifier is transmitted to Kra.sk.
- All provider configurations (including Kra.sk) are encrypted at rest using the master secret defined in `config/app.ini` (`security.provider_secret`).

Troubleshooting:

- "Kra.sk login failed" indicates incorrect credentials or an upstream API change.
- "Kra.sk subscription inactive" means the account lacks a current subscription; renew it then re‑try.
- Enable debug logging and tail `storage/logs` for deeper diagnostics.

### Friendly Filenames for Kra.sk Downloads

When a Kra.sk (or any provider) job is enqueued the scheduler now attempts to preserve a human‑readable filename instead of the opaque server side hash (e.g. `u5TJ7hIQxq...`). The logic:

1. Use the queued job `title` as the base name (this already comes from the search result's cleaned title in the UI).
2. Infer the container extension from the first resolved download URI path if it ends with a known video extension (`.mkv`, `.mp4`, `.avi`, `.mov`, `.webm`, `.mpg`, `.mpeg`). If none is detected it defaults to `.mkv`.
3. Sanitize the base (retain letters, numbers, spaces, commas, dots, dashes, underscores, parentheses, apostrophes) and collapse duplicate whitespace.
4. Pass the final value to aria2 via the `out` option so the file is written directly under that name in the downloads directory.

This improves Jellyfin library identification accuracy and avoids unnecessary post‑download renaming. Example:

`Animatrix - CZ, EN, EN+tit, HU, HU+tit (2004)` → `Animatrix - CZ, EN, EN+tit, HU, HU+tit (2004).mkv`

If you prefer the original server filename, remove or comment out the call to `deriveOutputFilename()` inside `bin/scheduler.php`.

## UI Panels

- Search – Provider search and queueing.
- Queue – Live job statuses (pause/resume/cancel/delete partial or final files) & infinite scrolling.
- Providers – Credential + status management.
- Settings – App paths, Jellyfin integration.
- Users – Admin user management.
- Audit Log – Historical actions.
- Storage – Disk free/used space.
- Queue Stats – Aggregated counts, total downloaded data, and success rate (manual refresh).

## Container Images

The provided `Dockerfile` installs nginx, php-fpm, aria2, and supervisor on top of `php:8.3-fpm-bookworm`. It copies the repository into `/var/www`, runs Composer in production mode, and seeds `downloads`, `library`, and `storage/logs` with correct ownership. `supervisord` orchestrates nginx, php-fpm, aria2c, and the PHP worker processes. Adjust build arguments `PUID`/`PGID` if host permissions require different ownership.
