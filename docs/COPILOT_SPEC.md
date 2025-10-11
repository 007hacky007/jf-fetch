# JF Fetch — Copilot Build Instructions

> **Purpose:** A web app to search and download videos from providers (implement **Webshare** first), maintain a server-side **queue** with background downloads, show **live progress**, and **auto-import** finished files into a **Jellyfin** library.
> **Constraints:** Pure **PHP 8.3** (no framework), **single Docker image** (nginx + php-fpm + aria2c + worker + scheduler), **all config in `config/*.ini`** (no hard-coded config), **English-commented code**, **runs on macOS & Linux**.

---

## 1) Features & UX

* **Search** across providers (default: all; checkbox to pick providers). For now implement **Webshare** only.
* **Results**: title, provider, size (human-readable). If available: **thumbnail**, **duration**, **resolution**, **video/audio codecs**, **bitrate**.
* **Queue**: add multiple items; **live progress**, **speed**, **ETA**; **cancel / pause / resume**, **priority**, **drag&drop reorder**.
* **Background downloads** continue without the UI open.
* **Notifications** on completion (toast via SSE events).
* **Auto-import to Jellyfin**: move file to a watched directory and trigger Jellyfin library refresh.
* **Disk usage widget**: total/used/free for `/downloads` and `/library` (refresh ~5s).
* **Multi-user RBAC**:

  * **admin**: manage users & providers; can edit **any** queue item.
  * **user**: cannot manage users/providers; can add items; may edit/cancel **only own** items.

---

## 2) Tech Stack (no frameworks)

* **Backend:** Pure **PHP 8.3** with **Composer autoload** (PSR-4). No Laravel/Symfony.
* **Routing:** tiny front controller `public/index.php` → maps `/api/*` to small PHP scripts in `public/api/`.
* **DB:** SQLite (default) or Postgres/MySQL via **PDO**. No ORM.
* **Queue:** Database-backed (`jobs` table).
* **Background processes (CLI PHP, same container):**

  * `bin/scheduler.php`: selects next jobs (priority/position; free space & concurrency guards) and enqueues to aria2.
  * `bin/worker.php`: polls aria2, updates progress, moves finished files into library, triggers Jellyfin refresh, emits SSE.
* **Downloader:** **aria2** via **JSON-RPC** (`app/Download/Aria2Client.php`).
* **Realtime:** **SSE** endpoint `public/api/jobs/stream.php` for job updates.
* **Frontend:** **vanilla JS (ES modules)** + **Tailwind CSS** (CDN). Responsive; drag&drop reorder.
* **Auth:** PHP **sessions** + `password_hash` / `password_verify`. Simple guards for RBAC.
* **Logging:** file/STDOUT.

---

## 3) Providers (plugin-like)

* **Implement only Webshare now** (others later, e.g., torrent via Transmission/qBittorrent/aria2 BT).
* **Webshare API documentation (mandatory):** [https://webshare.cz/apidoc/](https://webshare.cz/apidoc/)
* Provider interface:

  ```php
  interface VideoProvider {
      /** Normalized results across providers (may include thumbnail, duration, media specs). */
      public function search(string $query, int $limit = 50): array; // SearchItem[]
      /** Direct URL(s) for the downloader (or magnet for BT). */
      public function resolveDownloadUrl(string $externalIdOrUrl): string|array;
  }
  ```

---

## 4) API Endpoints (session-protected)

* **Auth & Users**

  * `POST /api/auth/login`, `POST /api/auth/logout`
  * `GET /api/users` (admin), `POST /api/users` (admin), `PATCH /api/users/{id}` (admin), `DELETE /api/users/{id}` (admin)
* **Providers (admin-only)**

  * `GET /api/providers`, `POST /api/providers`, `PATCH /api/providers/{id}`, `DELETE /api/providers/{id}`, `POST /api/providers/{id}/test`
* **Search**

  * `GET /api/search?q=...&providers[]=webshare&limit=50` → normalized `SearchItem[]`
* **Queue & Jobs**

  * `POST /api/queue` → `{ items: [{provider, external_id}], options?: {category?: 'Movies'|'TV'|...} }`
  * `GET /api/jobs?mine=1|0` → list (user sees own; admin sees all)
  * `PATCH /api/jobs/{id}/cancel|pause|resume`
  * `PATCH /api/jobs/{id}/priority` → `{priority:number}`
  * `POST /api/jobs/reorder` → `{order:[jobId...]}` (user: own items; admin: any)
  * `GET /api/jobs/stream` (SSE: `job.updated`, `job.completed`, `job.failed`, `job.removed`)
* **System**

  * `GET /api/system/storage` → `{ mounts:[{path,total,free}...], updated_at }`
  * `GET /api/system/health`

---

## 5) Frontend (vanilla, responsive)

Pages:

* **Search**: input + provider checkboxes; results show metadata; select items; **Add to queue**.
* **Queue**: live progress bars (SSE), speed, ETA; cancel/pause/resume; priority dropdown; drag&drop reorder (persist to server).
* **Admin/Providers**: add/edit credentials; test connection (admin only).
* **Admin/Users**: CRUD users/roles (admin only).
* **Storage Widget**: header/side block with used/free bars for `/downloads` and `/library`.

---

## 6) RBAC & Security

* Roles: `admin|user`.
* Users may operate **only their own** jobs; admins on all.
* Provider credentials stored **encrypted**, masked in UI.
* Audit log (user_id, action, subject, timestamp, meta).
* Optional quotas per user (max concurrent jobs, daily GB).
* Validate free space before starting a job (threshold in config).

---

## 7) Job Lifecycle (DB queue)

States: `queued → starting → downloading → completed | failed | canceled`

* **Scheduler**

  * Enforces `MAX_ACTIVE_DOWNLOADS` and minimum free-space guard.
  * Picks next `queued` job by `priority ASC, position ASC, created_at ASC` (transaction/lock).
  * Resolves direct URL via provider; calls `aria2.addUri`; sets `status='downloading'`.
* **Worker**

  * Polls `aria2.tellStatus` → updates `progress`, `speed_bps`, `eta_seconds` in DB; emits SSE updates.
  * On **complete**: move file `/downloads/...` → `/library/...`, set status `completed`, **POST Jellyfin /Library/Refresh**, emit `job.completed`.
  * On **error/removed**: set `failed`, emit `job.failed`.
  * Implements pause/resume/cancel via `aria2.pause/unpause/remove`.

---

## 8) Data Model (minimal)

```sql
users(id, name, email UNIQUE, password_hash, role ENUM('admin','user'), created_at, updated_at)

providers(id, key UNIQUE, name, enabled BOOL, config_json ENCRYPTED, created_at, updated_at)

jobs(id, user_id, provider_id, external_id, title, source_url, category,
     status ENUM('queued','starting','downloading','completed','failed','canceled'),
     progress INT DEFAULT 0, speed_bps BIGINT NULL, eta_seconds INT NULL,
     priority INT DEFAULT 100, position INT DEFAULT 0,
     aria2_gid VARCHAR(32) NULL,
     tmp_path TEXT NULL, final_path TEXT NULL,
     error_text TEXT NULL,
     created_at, updated_at)

notifications(id, user_id, job_id NULL, type VARCHAR(32), payload_json JSONB, created_at)
audit_log(id, user_id, action, subject_type, subject_id, payload_json, created_at)
```

---

## 9) Configuration (outside code)

* **All configuration** lives in `config/app.ini` (and optionally `config/secret.ini`), never hard-coded.
* Implement `app/Infra/Config.php` (INI loader with section and dot-notation). **No defaults in code.**
* Example `config/app.ini`:

  ```
  [app]
  base_url = "http://localhost:8080"
  max_active_downloads = 2
  min_free_space_gb = 5

  [paths]
  downloads = "/downloads"
  library   = "/library"

  [jellyfin]
  url = "http://jellyfin:8096"
  api_key = "CHANGEME"

  [aria2]
  rpc_url = "http://127.0.0.1:6800/jsonrpc"
  secret  = "CHANGEME"

  [db]
  dsn  = "sqlite:/var/www/storage/app.sqlite"
  user = ""
  pass = ""

  [webshare]
  wst = "CHANGEME"
  ```

---

## 10) Single-Container Docker (macOS & Linux)

**Goal:** One image that runs on both **macOS (Intel/Apple Silicon)** and **Linux**.

* **Image:** `php:8.3-fpm-bookworm` base; install **nginx**, **aria2**, **supervisor**.
* **Supervisord** runs: `nginx`, `php-fpm`, `aria2c`, `php bin/worker.php`, `php bin/scheduler.php`.
* **Volumes:** bind-mount:

  * `./downloads → /downloads`
  * `./library → /library`
  * `./config → /var/www/config:ro`
  * `./storage → /var/www/storage`
* **Non-root user:** create user/group via `PUID/PGID` build args to avoid permission issues.
* **Multi-arch build/run:**

  * Build: `docker buildx build --platform linux/amd64,linux/arm64 -t your/jf-fetch .`
  * Run override (if needed): `docker run --platform linux/amd64 …`
  * In compose, you can use `platform: linux/amd64` only if a dependency lacks arm64.
* **Ports:** expose `80` (nginx). `6800` (aria2 RPC) only if you need external RPC.

**Dockerfile (sketch)**

```dockerfile
FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y \
  nginx aria2 supervisor curl ca-certificates unzip git \
  && rm -rf /var/lib/apt/lists/*

ARG PUID=1000 PGID=1000
RUN groupadd -g ${PGID} app && useradd -m -u ${PUID} -g app app

WORKDIR /var/www
COPY . /var/www

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
 && composer install --no-dev --optimize-autoloader

RUN rm -f /etc/nginx/sites-enabled/default
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN mkdir -p /downloads /library /var/www/storage/logs \
 && chown -R app:app /downloads /library /var/www

USER app
EXPOSE 80 6800
CMD ["/usr/bin/supervisord","-n"]
```

**supervisord.conf (sketch)**

```ini
[supervisord]
logfile=/var/www/storage/logs/supervisord.log

[program:nginx]
command=/usr/sbin/nginx -g "daemon off;"
autostart=true
priority=10

[program:php-fpm]
command=/usr/local/sbin/php-fpm -F
autostart=true
priority=20

[program:aria2]
command=/usr/bin/aria2c --enable-rpc=true --rpc-listen-all=true --rpc-secret=%(ENV_ARIA2_SECRET)s \
  --dir=/downloads --continue=true --check-integrity=true --max-connection-per-server=8
autostart=true
priority=30

[program:worker]
command=php /var/www/bin/worker.php
autostart=true
priority=40

[program:scheduler]
command=php /var/www/bin/scheduler.php
autostart=true
priority=50
```

**docker-compose.yml (single service)**

```yaml
version: "3.9"
services:
  app:
    build:
      context: .
      args:
        PUID: ${PUID-1000}
        PGID: ${PGID-1000}
    # platform: linux/amd64   # uncomment on Apple Silicon if a dep lacks arm64
    ports: ["8080:80"]
    environment:
      ARIA2_SECRET: ${ARIA2_SECRET}
    volumes:
      - ./downloads:/downloads
      - ./library:/library
      - ./config:/var/www/config:ro
      - ./storage:/var/www/storage
```

---

## 11) Project Structure

```
repo/
  public/
    index.php
    ui/
      index.html
      app.js
      styles.css
    api/
      auth/{login.php,logout.php}
      search.php
      jobs/{list.php,queue.php,cancel.php,pause.php,resume.php,priority.php,reorder.php,stream.php}
      system/{storage.php,health.php}
      providers/{list.php,create.php,update.php,delete.php,test.php}
  app/
    Providers/{VideoProvider.php,WebshareProvider.php}
    Download/{Aria2Client.php}
    Domain/{Jobs.php,Users.php,Providers.php}
    Infra/{Db.php,Config.php,Auth.php,Events.php,Jellyfin.php,Util.php}
  bin/{worker.php,scheduler.php}
  config/{app.ini,secret.ini?}
  storage/{app.sqlite,logs/}
  library/
  nginx.conf
  supervisord.conf
  Dockerfile
  composer.json
  README.md
```

---

## 12) Coding Standards

* **English comments** in all PHP files (purpose, inputs/outputs, errors).
* **PSR-12** style; strict types where practical; small focused functions.
* API endpoints: correct status codes + JSON body.
* SSE: include heartbeat comments to keep connections alive.

---

## 13) Testing & QA

* **Unit:** provider normalization, progress/ETA math, RBAC guards.
* **Integration:** mock Webshare API; aria2 JSON-RPC against a local aria2.
* **E2E script:** search → queue → download → move → Jellyfin refresh → UI shows completed.

---

## 14) Acceptance Criteria

* Users see and manage **only their own** jobs; admins can manage all.
* Reorder (drag&drop) persists and scheduler respects it.
* Disk widget accurate within <5s.
* On completion: user sees toast; item moves to “Completed”.
* Insufficient disk: job remains queued with a clear UI reason.

---

## 15) Implementation Tasks for Copilot

1. **Bootstrap** Composer autoload; minimal front controller; API skeleton files.
2. Implement `app/Infra/Config.php` (INI loader; section + dot-notation). **No defaults in code.**
3. Implement `app/Infra/Db.php` (PDO; transactions; helpers).
4. Implement `app/Infra/Auth.php` (sessions; login/logout) and RBAC guards.
5. Implement `app/Download/Aria2Client.php` (`addUri`, `tellStatus`, `pause`, `unpause`, `remove`).
6. Implement **WebshareProvider** (`search`, `resolveDownloadUrl`) using [https://webshare.cz/apidoc/](https://webshare.cz/apidoc/); return normalized `SearchItem[]`.
7. Implement DB migrations (SQL) for `users`, `providers`, `jobs`, `notifications`, `audit_log`.
8. Build `bin/scheduler.php` & `bin/worker.php` loops.
9. Build API endpoints (Auth, Providers, Search, Queue/Jobs, System).
10. Build UI (`public/ui/index.html`, `app.js`) with Tailwind + ESM; SSE-based live progress; drag&drop reorder.
11. Package Docker (Dockerfile, supervisord.conf, compose); ensure macOS & Linux run; add `README.md`.
12. Add **English comments** across all PHP files.

---

## 16) Jellyfin Import — Moving Files & Refresh

* **Where the worker gets the file path:** from `aria2.tellStatus().files[0].path` (absolute path).
* **Move** from `/downloads/...` (staging) to `/library/...` according to category (Movies/TV) and your naming template.
* **Recommended naming & folders** for best detection:

  * **Movies**

    * `.../Movies/TITLE (YEAR).ext` **or**
    * `.../Movies/TITLE (YEAR)/TITLE (YEAR).ext`
      Reference: [https://jellyfin.org/docs/general/server/media/movies/](https://jellyfin.org/docs/general/server/media/movies/)
  * **TV Shows**

    * `.../TV/SHOW NAME/Season 01/SHOW NAME - S01E01 - EPISODE TITLE.ext`
      Reference: [https://jellyfin.org/docs/general/server/media/shows/](https://jellyfin.org/docs/general/server/media/shows/)
* **Permissions:** ensure the container user can **write** `/library`. Use `PUID/PGID` and optionally umask `002`. After move, set consistent perms (`0644` file, `0755` dirs).
* **Trigger a scan:** after every successful move, **globally refresh**:

  * `POST {JELLYFIN_URL}/Library/Refresh?api_key={JELLYFIN_API_KEY}`
* **Failures:** if move/permissions/disk-full fails, mark job `failed` and surface a clear error in UI.


---

## How to Save & Use with Copilot

* **Save this file as:** `docs/COPILOT_SPEC.md` (and link it from `README.md`).
* **VS Code / JetBrains Copilot Chat:**

  1. Open `docs/COPILOT_SPEC.md` in the editor (keep it visible).
  2. Ask Copilot Chat:
     *“Follow `docs/COPILOT_SPEC.md`. Start with tasks 1–3 under ‘Implementation Tasks for Copilot’ and scaffold the project.”*
  3. Keep the spec open while continuing: “Implement task 4…”, etc.
* **Copilot Edits (inline):** select repo root and prompt:
  *“Implement the project per `docs/COPILOT_SPEC.md`, tasks 1–3.”*
* **GitHub PRs/Issues:** link to `docs/COPILOT_SPEC.md` so Copilot uses it as context.
