-- Migration: extend jobs status enum with deleted state and track deletion timestamp
PRAGMA foreign_keys=OFF;

CREATE TABLE jobs_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    provider_id INTEGER NOT NULL,
    external_id VARCHAR(255) NOT NULL,
    title VARCHAR(512) NOT NULL,
    source_url TEXT NOT NULL,
    category VARCHAR(64) NULL,
    status VARCHAR(32) NOT NULL CHECK (status IN ('queued','starting','downloading','paused','completed','failed','canceled','deleted')),
    progress INTEGER NOT NULL DEFAULT 0,
    speed_bps BIGINT NULL,
    eta_seconds INTEGER NULL,
    priority INTEGER NOT NULL DEFAULT 100,
    position INTEGER NOT NULL DEFAULT 0,
    aria2_gid VARCHAR(64) NULL,
    tmp_path TEXT NULL,
    final_path TEXT NULL,
    error_text TEXT NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE
);

INSERT INTO jobs_new (
    id,
    user_id,
    provider_id,
    external_id,
    title,
    source_url,
    category,
    status,
    progress,
    speed_bps,
    eta_seconds,
    priority,
    position,
    aria2_gid,
    tmp_path,
    final_path,
    error_text,
    deleted_at,
    created_at,
    updated_at
)
SELECT
    id,
    user_id,
    provider_id,
    external_id,
    title,
    source_url,
    category,
    status,
    progress,
    speed_bps,
    eta_seconds,
    priority,
    position,
    aria2_gid,
    tmp_path,
    final_path,
    error_text,
    NULL AS deleted_at,
    created_at,
    updated_at
FROM jobs;

DROP TABLE jobs;
ALTER TABLE jobs_new RENAME TO jobs;

CREATE INDEX IF NOT EXISTS idx_jobs_user_id ON jobs(user_id);
CREATE INDEX IF NOT EXISTS idx_jobs_provider_id ON jobs(provider_id);
CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status);
CREATE INDEX IF NOT EXISTS idx_jobs_priority_position ON jobs(priority, position);

PRAGMA foreign_keys=ON;
