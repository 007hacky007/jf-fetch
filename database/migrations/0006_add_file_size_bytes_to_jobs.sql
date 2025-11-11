-- Migration: Add file_size_bytes column to jobs table
-- Stores the final file size to avoid filesystem I/O when calculating stats

ALTER TABLE jobs ADD COLUMN file_size_bytes BIGINT NULL;

CREATE INDEX IF NOT EXISTS idx_jobs_status_size ON jobs(status, file_size_bytes);
