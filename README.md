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

## Configuration

`config/app.ini` contains all runtime settings. The `[aria2]` section defines the JSON-RPC endpoint and secret used by `App\Download\Aria2Client`, while `[webshare]` stores the `wst` token consumed by `App\Providers\WebshareProvider`. Other sections configure Jellyfin, storage paths, and database connectivity. Avoid hard-coding configuration elsewhere—update the INI files instead.

### Adding the Webshare provider

1. Sign in with an administrator account and open the **Providers** tab.
2. Click **Add provider** and keep the default **Webshare** type selected.
3. Enter your Webshare **username (or email)** and **password**. The password is encrypted at rest and never shown again.
4. Choose a display name if you prefer something other than “Webshare”, leave the provider enabled, and click **Create provider**.

JF Fetch signs in to Webshare, requests a fresh API token, and stores it automatically—no manual JSON needed. Tokens are refreshed again whenever credentials are updated.

## Container Images

The provided `Dockerfile` installs nginx, php-fpm, aria2, and supervisor on top of `php:8.3-fpm-bookworm`. It copies the repository into `/var/www`, runs Composer in production mode, and seeds `downloads`, `library`, and `storage/logs` with correct ownership. `supervisord` orchestrates nginx, php-fpm, aria2c, and the PHP worker processes. Adjust build arguments `PUID`/`PGID` if host permissions require different ownership.
